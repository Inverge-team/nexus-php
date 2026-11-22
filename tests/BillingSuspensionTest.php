<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Contracts\Dispatcher;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\BillingSuspendedException;
use Inverge\Nexus\Exception\TransportException;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\Http\BillingSuspension;
use Inverge\Nexus\Http\FileSuspensionStore;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Resource\Errors;
use Inverge\Nexus\Tests\Support\FakeTransport;
use Inverge\Nexus\Tests\Support\MemorySuspensionStore;
use PHPUnit\Framework\TestCase;

final class BillingSuspensionTest extends TestCase
{
    private MemorySuspensionStore $store;

    protected function setUp(): void
    {
        $this->store = new MemorySuspensionStore();
        BillingSuspension::useStore($this->store);
    }

    protected function tearDown(): void
    {
        BillingSuspension::useStore(null);
    }

    private static function suspended(?int $retryAfter = null): ApiResponse
    {
        $details = ['amountDue' => 54.56, 'invoiceNumbers' => ['INV-2026-10-0001']];
        if ($retryAfter !== null) {
            $details['retryAfterSeconds'] = $retryAfter;
        }

        return new ApiResponse(402, json_encode(['error' => [
            'code' => 'billing_suspended',
            'message' => 'API access is suspended: USD 54.56 is past due on invoice INV-2026-10-0001.',
            'details' => $details,
        ]]));
    }

    /** @return array{0: NexusClient, 1: FakeTransport} */
    private function client(ApiResponse ...$responses): array
    {
        $transport = new FakeTransport(...$responses);

        return [new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport), $transport];
    }

    public function testA402StopsEveryFurtherRequest(): void
    {
        [$nexus, $transport] = $this->client(self::suspended());

        try {
            $nexus->push()->sendToUser('u1', ['title' => 'Hi', 'body' => 'there']);
            $this->fail('expected a suspension');
        } catch (BillingSuspendedException $e) {
            $this->assertFalse($e->local);
            $this->assertSame(402, $e->status);
            $this->assertSame(BillingSuspension::DEFAULT_SECONDS, $e->retryAfter);
            $this->assertSame(['INV-2026-10-0001'], $e->details['invoiceNumbers']);
        }

        for ($i = 0; $i < 50; $i++) {
            try {
                $nexus->push()->sendToUser('u1', ['title' => 'Hi', 'body' => 'there']);
                $this->fail('expected a local refusal');
            } catch (BillingSuspendedException $e) {
                $this->assertTrue($e->local);
            }
        }

        $this->assertCount(1, $transport->requests, 'only the first call reached the API');
    }

    public function testTelemetryIsDroppedQuietlyWhileSuspended(): void
    {
        [$nexus, $transport] = $this->client(self::suspended());

        $this->assertSame([], $nexus->errors()->capture('boom')); // the 402 itself: quiet
        $nexus->events()->capture('signup');
        $nexus->logs()->batch([['level' => 'info', 'message' => 'x']]);
        $nexus->errors()->capture('boom again');

        $this->assertCount(1, $transport->requests);
    }

    public function testNothingIsQueuedWhileSuspended(): void
    {
        $queued = new class () implements Dispatcher {
            public int $calls = 0;

            public function dispatch(string $method, string $path, ?array $json = null, array $headers = []): ?array
            {
                $this->calls++;

                return null;
            }
        };
        [$nexus] = $this->client(self::suspended());
        $this->assertSame([], $nexus->errors()->capture('trip it'));

        $queuedClient = $nexus->withDispatcher($queued);
        $queuedClient->errors()->capture('x');
        $queuedClient->events()->capture('y');

        $this->assertSame(0, $queued->calls);
    }

    public function testThePauseIsSharedByEveryClientOnTheStore(): void
    {
        [$a] = $this->client(self::suspended());
        $this->assertSame([], $a->errors()->capture('trip it'));

        BillingSuspension::useStore($this->store); // a fresh process: empty memory, same cache
        [$b, $bTransport] = $this->client();
        $this->assertSame([], $b->errors()->capture('other worker'));

        $this->assertCount(0, $bTransport->requests);
    }

    public function testAfterThePauseOneProbeGoesOutAndSuccessRestoresAccess(): void
    {
        [$nexus, $transport] = $this->client(self::suspended(), new ApiResponse(200, '{"ok":true}'));
        $this->assertSame([], $nexus->errors()->capture('trip it'));

        $this->store->expire();
        BillingSuspension::useStore($this->store);

        $nexus->errors()->capture('probe');
        $this->assertCount(2, $transport->requests);
        $this->assertSame([], $this->store->values, 'access restored — pause forgotten');

        $nexus->errors()->capture('normal again');
        $this->assertCount(3, $transport->requests);
    }

    public function testAProbeThatIsRefusedStartsANewPause(): void
    {
        [$nexus, $transport] = $this->client(self::suspended(), self::suspended());
        $nexus->errors()->capture('trip it');

        $this->store->expire();
        BillingSuspension::useStore($this->store);
        $nexus->errors()->capture('probe');
        $nexus->errors()->capture('held back');

        $this->assertCount(2, $transport->requests);
        $this->assertGreaterThan(time(), array_values($this->store->values)[0]);
    }

    public function testWhileOneProcessProbesTheOthersWait(): void
    {
        [$nexus] = $this->client(self::suspended());
        $nexus->errors()->capture('trip it');
        $this->store->expire();

        // Process 1 takes the probe lease…
        BillingSuspension::useStore($this->store);
        $this->assertNull(BillingSuspension::check(new Config('nxs_test', 'https://api.example.test'), '/partner/errors'));

        // …so process 2 is still held back.
        BillingSuspension::useStore($this->store);
        $this->assertNotNull(BillingSuspension::check(new Config('nxs_test', 'https://api.example.test'), '/partner/errors'));
    }

    public function testTheApiChoosesThePauseLengthWithinBounds(): void
    {
        [$nexus] = $this->client(self::suspended(120));
        try {
            $nexus->push()->sendToUser('u1', ['title' => 'Hi', 'body' => 'there']);
        } catch (BillingSuspendedException $e) {
            $this->assertSame(120, $e->retryAfter);
        }

        BillingSuspension::reset(new Config('nxs_test', 'https://api.example.test'));
        [$nexus] = $this->client(self::suspended(999999));
        try {
            $nexus->push()->sendToUser('u1', ['title' => 'Hi', 'body' => 'there']);
        } catch (BillingSuspendedException $e) {
            $this->assertSame(3600, $e->retryAfter);
        }
    }

    public function testMessagingIsPrepaidAndNeverHeldBack(): void
    {
        [$nexus, $transport] = $this->client(self::suspended(), new ApiResponse(202, '{"id":"m1","status":"queued"}'));
        $nexus->errors()->capture('trip it');

        $nexus->messaging()->send('sms', '+9647501234567', body: 'Your code is 1234');

        $this->assertCount(2, $transport->requests);
        $this->assertStringEndsWith('/partner/messaging/messages', $transport->lastRequest()['url']);
    }

    public function testAnEmptyWalletIsNotASuspension(): void
    {
        [$nexus, $transport] = $this->client(
            new ApiResponse(402, '{"error":{"code":"insufficient_balance","message":"Top up your wallet."}}'),
        );

        try {
            $nexus->messaging()->send('sms', '+9647501234567', body: 'x');
        } catch (ApiException $e) {
            $this->assertNotInstanceOf(BillingSuspendedException::class, $e);
        }
        $this->assertSame([], $this->store->values);

        $nexus->events()->capture('still sent');
        $this->assertCount(2, $transport->requests);
    }

    public function testDeliveryFailuresAreNeverReportedToErrors(): void
    {
        $this->assertTrue(Errors::isDeliveryFailure(BillingSuspension::localException(10)));
        $this->assertTrue(Errors::isDeliveryFailure(new ApiException('suspended', 402, 'billing_suspended')));
        $this->assertTrue(Errors::isDeliveryFailure(new ApiException('down', 503)));
        $this->assertTrue(Errors::isDeliveryFailure(new TransportException('timeout')));
        $this->assertTrue(Errors::isDeliveryFailure(new \RuntimeException('job failed', 0, new ApiException('down', 502))));
        $this->assertFalse(Errors::isDeliveryFailure(new ApiException('bad title', 422, 'validation_error')));
        $this->assertFalse(Errors::isDeliveryFailure(new \LogicException('app bug')));

        [$nexus, $transport] = $this->client();
        $this->assertSame([], $nexus->errors()->captureException(new ApiException('suspended', 402, 'billing_suspended')));
        $this->assertCount(0, $transport->requests);
    }

    public function testOldQueuedSuspensionReportsAreRecognised(): void
    {
        $this->assertTrue(BillingSuspension::isSuspensionReport('/partner/errors', [
            'type' => 'Inverge\\Nexus\\Exception\\ApiException',
            'message' => 'API access is suspended: USD 54.56 is past due on invoice INV-2026-10-0001.',
        ]));
        $this->assertFalse(BillingSuspension::isSuspensionReport('/partner/errors', [
            'type' => 'App\\Exceptions\\Boom', 'message' => 'API access is suspended',
        ]));
        $this->assertFalse(BillingSuspension::isSuspensionReport('/partner/events', ['type' => 'x']));
    }

    public function testTheFileStoreRoundTrips(): void
    {
        $dir = sys_get_temp_dir() . '/nexus-test-' . bin2hex(random_bytes(4));
        $store = new FileSuspensionStore($dir);
        $this->assertNull($store->get('k'));
        $store->put('k', 1234567890);
        $this->assertSame(1234567890, $store->get('k'));
        $store->forget('k');
        $this->assertNull($store->get('k'));
        @rmdir($dir . '/inverge-nexus');
        @rmdir($dir);
    }
}

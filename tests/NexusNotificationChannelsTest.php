<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\Laravel\Notifications\NexusLiveActivityChannel;
use Inverge\Nexus\Laravel\Notifications\NexusLiveActivityMessage;
use Inverge\Nexus\Laravel\Notifications\NexusPushChannel;
use Inverge\Nexus\Laravel\Notifications\NexusPushMessage;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeNotifiable;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class NexusNotificationChannelsTest extends TestCase
{
    /** @return array{0: NexusClient, 1: FakeTransport} */
    private function make(): array
    {
        $transport = new FakeTransport(new ApiResponse(200, '{"sent":1,"failed":0,"ios":1,"android":1}'));

        return [new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport), $transport];
    }

    private function notification(string $method, \Closure $build): object
    {
        return new class($method, $build) {
            public function __construct(private string $method, private \Closure $build)
            {
            }

            public function toNexusPush(object $n): mixed
            {
                return $this->method === 'toNexusPush' ? ($this->build)($n) : null;
            }

            public function toNexusLiveActivity(object $n): mixed
            {
                return $this->method === 'toNexusLiveActivity' ? ($this->build)($n) : null;
            }
        };
    }

    // ---- push ----

    public function testPushGoesToTheNotifiable(): void
    {
        [$client, $transport] = $this->make();
        $n = $this->notification('toNexusPush', fn () => NexusPushMessage::create('Your order shipped', 'Track it')
            ->data(['screen' => '/orders/42'])->button('track', 'Track')->iosBadge(1));

        $result = (new NexusPushChannel($client))->send(new FakeNotifiable(['nexus' => 'CLIENTS.36586']), $n);

        $this->assertSame(1, $result['sent']);
        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/push/send', $req['url']);
        $this->assertSame('Your order shipped', $req['json']['title']);
        $this->assertSame('Track it', $req['json']['body']);
        $this->assertSame(['CLIENTS.36586'], $req['json']['distinctIds']);
        $this->assertSame(['screen' => '/orders/42'], $req['json']['data']);
        $this->assertSame(1, $req['json']['options']['iosBadge']);
    }

    public function testPushChannelRouteWinsAndListsAreSupported(): void
    {
        [$client, $transport] = $this->make();
        $n = $this->notification('toNexusPush', fn () => NexusPushMessage::create('Hi'));
        (new NexusPushChannel($client))->send(new FakeNotifiable(['nexus-push' => ['a', 'b', 'a'], 'nexus' => 'ignored']), $n);
        $this->assertSame(['a', 'b'], $transport->lastRequest()['json']['distinctIds']);

        (new NexusPushChannel($client))->send(new FakeNotifiable(['nexus' => ['distinctId' => 'u_9', 'deviceKey' => 'd']]), $n);
        $this->assertSame(['u_9'], $transport->lastRequest()['json']['distinctIds']);
    }

    public function testPushExplicitTargetSkipsTheRoute(): void
    {
        [$client, $transport] = $this->make();
        $n = $this->notification('toNexusPush', fn () => NexusPushMessage::create('Sale!')->toSegment('vip'));
        (new NexusPushChannel($client))->send(new FakeNotifiable(['nexus' => 'u_1']), $n);
        $json = $transport->lastRequest()['json'];
        $this->assertSame(['vip'], $json['segmentIds']);
        $this->assertArrayNotHasKey('distinctIds', $json);
    }

    public function testPushWithoutRecipientExplains(): void
    {
        [$client] = $this->make();
        $n = $this->notification('toNexusPush', fn () => NexusPushMessage::create('Hi'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/routeNotificationForNexus/');
        (new NexusPushChannel($client))->send(new FakeNotifiable(), $n);
    }

    public function testPushNullSkipsAndUnknownMethodsFail(): void
    {
        [$client, $transport] = $this->make();
        $this->assertNull((new NexusPushChannel($client))->send(new FakeNotifiable(['nexus' => 'u']), $this->notification('toNexusPush', fn () => null)));
        $this->assertSame([], $transport->requests);

        $this->expectException(\BadMethodCallException::class);
        NexusPushMessage::create('x')->sendNow();
    }

    // ---- live activities ----

    public function testTemplatedStartGoesToTheNotifiable(): void
    {
        [$client, $transport] = $this->make();
        $n = $this->notification('toNexusLiveActivity', fn () => NexusLiveActivityMessage::create('order_42')->start('order_tracking')
            ->step('pending')->etaAt(1792584000)->values(['driver' => 'Ahmed']));

        $result = (new NexusLiveActivityChannel($client))->send(new FakeNotifiable(['nexus' => 'CLIENTS.36586']), $n);

        $this->assertSame(1, $result['ios']);
        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/live-activities/start', $req['url']);
        $this->assertSame('order_tracking', $req['json']['template']);
        $this->assertSame(['CLIENTS.36586'], $req['json']['distinctIds']);
        $this->assertSame(['step' => 'pending', 'etaAt' => 1792584000, 'values' => ['driver' => 'Ahmed']], $req['json']['data']);
    }

    public function testUpdateAndEndNeedNoRecipient(): void
    {
        [$client, $transport] = $this->make();
        $channel = new NexusLiveActivityChannel($client);

        $channel->send(new FakeNotifiable(), $this->notification('toNexusLiveActivity', fn () => NexusLiveActivityMessage::create('order_42')->update()->step('arrived')));
        $this->assertSame('https://api.example.test/partner/live-activities/order_42/update', $transport->lastRequest()['url']);
        $this->assertSame(['data' => ['step' => 'arrived']], $transport->lastRequest()['json']);

        $channel->send(new FakeNotifiable(), $this->notification('toNexusLiveActivity', fn () => NexusLiveActivityMessage::create('order_42')->end()->step('completed')));
        $this->assertSame('https://api.example.test/partner/live-activities/order_42/end', $transport->lastRequest()['url']);
        $this->assertSame(['data' => ['step' => 'completed']], $transport->lastRequest()['json']);
    }

    public function testSharedAndPlainStarts(): void
    {
        [$client, $transport] = $this->make();
        $channel = new NexusLiveActivityChannel($client);

        $channel->send(new FakeNotifiable(), $this->notification('toNexusLiveActivity', fn () => NexusLiveActivityMessage::create('match_9')->start('match')->shared()->step('kickoff')));
        $this->assertArrayNotHasKey('distinctIds', $transport->lastRequest()['json']);

        $channel->send(new FakeNotifiable(['nexus-live-activity' => 'u_2']), $this->notification('toNexusLiveActivity', fn () => NexusLiveActivityMessage::create('o9')->startActivity('DeliveryAttributes')->status('Preparing')));
        $json = $transport->lastRequest()['json'];
        $this->assertSame('DeliveryAttributes', $json['activityType']);
        $this->assertSame(['status' => 'Preparing'], $json['contentState']);
        $this->assertSame(['u_2'], $json['distinctIds']);
    }

    public function testStartWithoutRecipientExplains(): void
    {
        [$client] = $this->make();
        $this->expectException(\LogicException::class);
        (new NexusLiveActivityChannel($client))->send(new FakeNotifiable(), $this->notification('toNexusLiveActivity', fn () => NexusLiveActivityMessage::create('o')->start('t')));
    }

    public function testWrongReturnTypeIsAClearError(): void
    {
        [$client] = $this->make();
        $this->expectException(\UnexpectedValueException::class);
        (new NexusLiveActivityChannel($client))->send(new FakeNotifiable(['nexus' => 'u']), $this->notification('toNexusLiveActivity', fn () => NexusPushMessage::create('x')));
    }

    public function testCreateWithoutAStepIsAClearError(): void
    {
        [$client, $transport] = $this->make();
        try {
            (new NexusLiveActivityChannel($client))->send(new FakeNotifiable(['nexus' => 'u']), $this->notification('toNexusLiveActivity', fn () => NexusLiveActivityMessage::create('order_42')->step('arrived')));
            $this->fail('expected a LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('->start($template), ->update() or ->end()', $e->getMessage());
        }
        $this->assertSame([], $transport->requests);
    }

    public function testCreateNeedsAnId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NexusLiveActivityMessage::create('');
    }

    public function testTheLastStepChosenWins(): void
    {
        [$client, $transport] = $this->make();
        (new NexusLiveActivityChannel($client))->send(new FakeNotifiable(['nexus' => 'u']), $this->notification('toNexusLiveActivity', fn () => NexusLiveActivityMessage::create('o1')->startActivity('T')->start('order_tracking')->step('pending')));
        $json = $transport->lastRequest()['json'];
        $this->assertSame('order_tracking', $json['template']);
        $this->assertArrayNotHasKey('activityType', $json);
    }
}

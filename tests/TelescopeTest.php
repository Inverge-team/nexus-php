<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Services;
use Inverge\Nexus\Telescope\Telescope;
use Inverge\Nexus\Telescope\TraceContext;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class TelescopeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nexus-telescope-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $options */
    private function telescope(FakeTransport $t, array $options = [], ?Services $services = null): Telescope
    {
        $client = new NexusClient(new Config('nxs_test', 'https://api.example.test', 10.0, [], $services ?? Services::allEnabled()));

        return new Telescope($client, $options + ['transport' => $t, 'cache_dir' => $this->dir, 'service' => 'shop']);
    }

    public function testRecordsAJourneyAndSendsItWhenTheOutermostBatchFinishes(): void
    {
        $t = new FakeTransport();
        $tel = $this->telescope($t);
        $tel->start('request', ['method' => 'GET', 'uri' => '/orders/7', 'headers' => ['Authorization' => 'Bearer x']], [
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ]);
        $tel->query('select * from orders where id = ?', [7], 1.5, 'mysql');
        $result = $tel->span('pricing', function () use ($tel) {
            $tel->query('select price from prices where sku = ?', ['A1'], 0.4, 'mysql');

            return 42;
        });
        $tel->log('warning', 'low stock', ['sku' => 'A1', 'password' => 'p']);
        $tel->user(['id' => '9', 'email' => 'a@b.c']);
        $tel->tag('vip');
        $this->assertSame(42, $result);
        $this->assertCount(0, $t->requests, 'nothing is sent mid-request');

        $tel->finish(['responseStatus' => 200], '200');

        $this->assertCount(1, $t->requests);
        $req = $t->lastRequest();
        $this->assertSame('https://api.example.test/partner/telescope', $req['url']);
        $body = $req['json'];
        $this->assertSame('shop', $body['service']);
        $batch = $body['batches'][0];
        $this->assertSame('request', $batch['type']);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $batch['traceId'], 'joins the incoming trace');
        $this->assertSame('00f067aa0ba902b7', $batch['parentSpanId']);
        $this->assertSame('********', $batch['content']['headers']['Authorization']);
        $this->assertSame(['id' => '9', 'email' => 'a@b.c'], $batch['user']);
        $this->assertSame(['vip'], $batch['tags']);
        $this->assertCount(4, $batch['entries']);

        [$q1, $span, $q2, $log] = $batch['entries'];
        $this->assertSame($batch['id'], $q1['parentId'], 'top-level entries hang off the batch');
        $this->assertSame('span', $span['type']);
        $this->assertSame($span['id'], $q2['parentId'], 'entries inside a span nest under it');
        $this->assertArrayHasKey('durationMs', $span);
        $this->assertSame('********', $log['content']['context']['password']);
    }

    public function testNestedBatchesAndPropagation(): void
    {
        $t = new FakeTransport();
        $tel = $this->telescope($t);
        $tel->start('request', ['uri' => '/checkout']);
        $prop = $tel->propagation('aaaaaaaaaaaaaaaa');
        $this->assertNotNull($prop);
        // a sync-queue job runs inside the request
        $tel->start('job', ['name' => 'SendInvoice'], $prop);
        $tel->query('insert into invoices values (?)', [1], 2.0);
        $tel->finish([], 'processed');
        $this->assertCount(0, $t->requests, 'inner batch waits for the outer one');
        $tel->finish(['responseStatus' => 201]);
        $batches = $t->lastRequest()['json']['batches'];
        $this->assertCount(2, $batches);
        $this->assertSame('job', $batches[0]['type']);
        $this->assertSame($batches[1]['traceId'], $batches[0]['traceId']);
        $this->assertSame('aaaaaaaaaaaaaaaa', $batches[0]['parentSpanId']);
        $this->assertCount(1, $batches[0]['entries'], 'the query went to the job, not the request');
        $this->assertCount(0, $batches[1]['entries']);
    }

    public function testTailSamplingKeepsFailuresAndUsesTheConsoleConfig(): void
    {
        $t = new FakeTransport(new ApiResponse(202, json_encode(['written' => 1, 'config' => ['enabled' => true, 'sampleRate' => 0.0, 'slowRequestMs' => 1000, 'ignoreTypes' => ['cache'], 'monitoredTags' => ['Auth:1']]])));
        $tel = $this->telescope($t);
        $tel->start('request', ['uri' => '/a']);
        $tel->finish(['responseStatus' => 200]);
        $this->assertCount(1, $t->requests);

        // From now on the console says: sample 0%, never record cache.
        $tel->start('request', ['uri' => '/b']);
        $this->assertNull($tel->record('cache', ['action' => 'hit', 'key' => 'k']));
        $tel->finish(['responseStatus' => 200]);
        $this->assertCount(1, $t->requests, 'sampled out');

        $tel->start('request', ['uri' => '/c']);
        $tel->finish(['responseStatus' => 500]);
        $this->assertCount(2, $t->requests, 'failures are always kept');

        $tel->start('request', ['uri' => '/d']);
        $tel->user(['id' => '1']);
        $tel->finish(['responseStatus' => 200]);
        $this->assertCount(3, $t->requests, 'monitored user is always kept');

        $tel->start('request', ['uri' => '/e']);
        $tel->exception(new \RuntimeException('boom'));
        $tel->finish(['responseStatus' => 200]);
        $this->assertCount(4, $t->requests, 'an unhandled exception is a failure');
        $exception = $t->lastRequest()['json']['batches'][0]['entries'][0];
        $this->assertSame('RuntimeException', $exception['content']['class']);
        $this->assertNotEmpty($exception['content']['stack']);
    }

    public function testTheServiceSwitchTurnsItOff(): void
    {
        $t = new FakeTransport();
        $tel = $this->telescope($t, [], Services::fromArray(['telescope' => false]));
        $this->assertNull($tel->start('request'));
        $tel->finish();
        $this->assertCount(0, $t->requests);
    }

    public function testDeliveryFailuresNeverThrow(): void
    {
        $t = new FakeTransport(new ApiResponse(500, '{"error":{"code":"x","message":"down"}}'));
        $tel = $this->telescope($t);
        $tel->start('command', ['command' => 'reports:send']);
        $tel->finish(['exitCode' => 0], '0');
        $this->assertCount(1, $t->requests);
    }

    public function testTraceparent(): void
    {
        $this->assertNull(TraceContext::parse('garbage'));
        $this->assertNull(TraceContext::parse('00-00000000000000000000000000000000-00f067aa0ba902b7-01'));
        $t = new FakeTransport();
        $tel = $this->telescope($t);
        $tel->start('request');
        $tp = $tel->traceparent('bbbbbbbbbbbbbbbb');
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-bbbbbbbbbbbbbbbb-01$/', (string) $tp);
        $tel->finish();
    }

    public function testIgnoredStatusesAreNeverSent(): void
    {
        $t = new FakeTransport(new ApiResponse(202, json_encode(['written' => 1, 'config' => ['enabled' => true, 'ignoreStatuses' => ['3xx']]])));
        $tel = $this->telescope($t, ['ignore_statuses' => ['200', '401-403']]);
        $this->assertTrue($tel->ignoresStatus(200));
        $this->assertTrue($tel->ignoresStatus('402'));
        $this->assertFalse($tel->ignoresStatus('404'));

        $tel->start('request', ['uri' => '/ok']);
        $tel->query('select 1', [], 0.1, 'mysql');
        $tel->finish(['responseStatus' => 200], '200');
        $this->assertCount(0, $t->requests, 'an ignored status is dropped before sending');

        $tel->start('request', ['uri' => '/missing']);
        $tel->finish(['responseStatus' => 404], '404');
        $this->assertCount(1, $t->requests);

        // The console's list arrived with that delivery.
        $tel->start('request', ['uri' => '/moved']);
        $tel->finish(['responseStatus' => 301], '301');
        $this->assertCount(1, $t->requests);

        // Only requests: a command's exit code is not an HTTP status.
        $tel->start('command', ['command' => 'x']);
        $tel->finish(['exitCode' => 0], '200');
        $this->assertCount(2, $t->requests);
    }

    public function testIgnoredPathsAndEntryCap(): void
    {
        $t = new FakeTransport();
        $tel = $this->telescope($t, ['ignore_paths' => ['/horizon*'], 'max_entries' => 2]);
        $this->assertTrue($tel->ignoresPath('/horizon/api/stats'));
        $this->assertFalse($tel->ignoresPath('/orders'));
        $tel->start('request');
        for ($i = 0; $i < 5; $i++) {
            $tel->record('event', ['name' => "e{$i}"]);
        }
        $tel->finish();
        $batch = $t->lastRequest()['json']['batches'][0];
        $this->assertCount(2, $batch['entries']);
        $this->assertSame(3, $batch['content']['droppedEntries']);
    }
}

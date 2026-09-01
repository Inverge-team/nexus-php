<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Laravel\Notifications\NexusMessage;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class NexusMessageTest extends TestCase
{
    private function client(FakeTransport $transport): NexusClient
    {
        return new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport);
    }

    public function testSendsEveryOperationWithIdentity(): void
    {
        $transport = new FakeTransport();

        NexusMessage::create()
            ->to('user_7')
            ->event('order_shipped', ['order' => 42])
            ->emit('orders:42', 'status', ['state' => 'shipped'])
            ->info('shipped', ['order' => 42])
            ->captureException(new \RuntimeException('boom'))
            ->send($this->client($transport));

        $reqs = $transport->requests;
        $this->assertCount(4, $reqs);

        // event — identity applied
        $this->assertSame('https://api.example.test/partner/events', $reqs[0]['url']);
        $this->assertSame('order_shipped', $reqs[0]['json']['events'][0]['name']);
        $this->assertSame('user_7', $reqs[0]['json']['distinctId']);

        // emit — room based, no identity
        $this->assertSame('https://api.example.test/partner/rooms/orders%3A42/emit', $reqs[1]['url']);
        $this->assertSame(['status'], $reqs[1]['json']['events']);
        $this->assertSame(['state' => 'shipped'], $reqs[1]['json']['payload']);

        // log — level mapped, identity applied, context preserved
        $this->assertSame('https://api.example.test/partner/logs', $reqs[2]['url']);
        $this->assertSame('info', $reqs[2]['json']['logs'][0]['level']);
        $this->assertSame(['order' => 42], $reqs[2]['json']['logs'][0]['context']);
        $this->assertSame('user_7', $reqs[2]['json']['distinctId']);

        // exception capture
        $this->assertSame('https://api.example.test/partner/errors', $reqs[3]['url']);
        $this->assertSame(\RuntimeException::class, $reqs[3]['json']['type']);
        $this->assertSame('user_7', $reqs[3]['json']['distinctId']);
    }

    public function testRouteSuppliesDefaultDistinctId(): void
    {
        $transport = new FakeTransport();

        $message = NexusMessage::create()->event('x');
        $message->applyRoute('user_9'); // e.g. routeNotificationForNexus() => 'user_9'
        $message->send($this->client($transport));

        $this->assertSame('user_9', $transport->requests[0]['json']['distinctId']);
    }

    public function testExplicitIdentityWinsOverRoute(): void
    {
        $transport = new FakeTransport();

        $message = NexusMessage::create()->to('explicit')->event('x');
        $message->applyRoute('from_route');
        $message->send($this->client($transport));

        $this->assertSame('explicit', $transport->requests[0]['json']['distinctId']);
    }
}

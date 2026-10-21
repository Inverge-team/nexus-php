<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\Laravel\Notifications\NexusChannel;
use Inverge\Nexus\Laravel\Notifications\NexusDeliveryLedger;
use Inverge\Nexus\Laravel\Notifications\NexusLiveActivityMessage;
use Inverge\Nexus\Laravel\Notifications\NexusMessage;
use Inverge\Nexus\Laravel\Notifications\NexusPushMessage;
use Inverge\Nexus\Laravel\Notifications\NexusRealtimeChannel;
use Inverge\Nexus\Laravel\Notifications\NexusRealtimeMessage;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeNotifiable;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class MemoryLedger implements NexusDeliveryLedger
{
    /** @var array<string, true> */
    public array $keys = [];

    public function delivered(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    public function markDelivered(string $key): void
    {
        $this->keys[$key] = true;
    }
}

/** A notification with push + Live Activity + realtime, like an order status change. */
final class OrderShipped
{
    /** @param list<string> $via */
    public function __construct(public array $via = ['nexus'], public ?string $id = null)
    {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->via;
    }

    public function toNexusPush(object $n): NexusPushMessage
    {
        return NexusPushMessage::create('Shipped');
    }

    public function toNexusLiveActivity(object $n): NexusLiveActivityMessage
    {
        return NexusLiveActivityMessage::create('order_42')->update()->step('on_the_way');
    }

    public function toNexusRealtime(object $n): NexusRealtimeMessage
    {
        return NexusRealtimeMessage::create()->emit('orders:42', 'status', ['state' => 'shipped']);
    }
}

final class NexusAllChannelsTest extends TestCase
{
    private function client(FakeTransport $t): NexusClient
    {
        return new NexusClient(new Config('nxs_test', 'https://api.example.test'), $t);
    }

    /** @return list<string> request paths */
    private static function paths(FakeTransport $t): array
    {
        return array_map(static fn ($r) => (string) parse_url($r['url'], PHP_URL_PATH), $t->requests);
    }

    public function testNexusSendsEveryProductTheNotificationHas(): void
    {
        $t = new FakeTransport();
        $results = (new NexusChannel($this->client($t)))->send(new FakeNotifiable(['nexus' => 'u_1']), new OrderShipped());

        $this->assertSame(['push', 'liveActivity', 'realtime'], array_keys($results));
        $this->assertSame(['/partner/push/send', '/partner/live-activities/order_42/update', '/partner/rooms/orders%3A42/emit'], self::paths($t));
        $this->assertSame(['u_1'], $t->requests[0]['json']['distinctIds']);
    }

    public function testAProductWithItsOwnChannelInViaIsNotSentTwice(): void
    {
        $t = new FakeTransport();
        // via(['nexus', 'nexus-push']): Laravel calls the push channel itself → the all-in-one skips push
        (new NexusChannel($this->client($t)))->send(new FakeNotifiable(['nexus' => 'u_1']), new OrderShipped(['nexus', 'nexus-push']));
        $this->assertNotContains('/partner/push/send', self::paths($t));

        $t2 = new FakeTransport();
        (new NexusChannel($this->client($t2)))->send(new FakeNotifiable(['nexus' => 'u_1']), new OrderShipped(['nexus', NexusRealtimeChannel::class]));
        $this->assertNotContains('/partner/rooms/orders%3A42/emit', self::paths($t2));
    }

    public function testAFailureDoesNotBlockTheOthersAndARetryResendsOnlyWhatFailed(): void
    {
        $ledger = new MemoryLedger();
        $notifiable = new FakeNotifiable(['nexus' => 'u_1']);
        $notification = new OrderShipped(['nexus'], 'notif-uuid-1'); // Laravel sets the id before queueing

        // 1st attempt: push OK, Live Activity 503, realtime OK → the job fails
        $t = new FakeTransport(new ApiResponse(200, '{"sent":1}'), new ApiResponse(503, '{"message":"busy"}'), new ApiResponse(200, '{}'));
        try {
            (new NexusChannel($this->client($t), $ledger))->send($notifiable, $notification);
            $this->fail('the job must fail so Laravel retries it');
        } catch (ApiException) {
        }
        $this->assertSame(['/partner/push/send', '/partner/live-activities/order_42/update', '/partner/rooms/orders%3A42/emit'], self::paths($t));

        // the retry: only the Live Activity is sent again — no second push, no second emit
        $retry = new FakeTransport();
        $results = (new NexusChannel($this->client($retry), $ledger))->send($notifiable, $notification);
        $this->assertSame(['/partner/live-activities/order_42/update'], self::paths($retry));
        $this->assertSame(['liveActivity'], array_keys($results));

        // a third run (e.g. a duplicate job) sends nothing
        $again = new FakeTransport();
        (new NexusChannel($this->client($again), $ledger))->send($notifiable, $notification);
        $this->assertSame([], $again->requests);
    }

    public function testWithoutAnIdThereIsNoDedupe(): void
    {
        $ledger = new MemoryLedger();
        $t = new FakeTransport();
        $channel = new NexusChannel($this->client($t), $ledger);
        $channel->send(new FakeNotifiable(['nexus' => 'u_1']), new OrderShipped());
        $channel->send(new FakeNotifiable(['nexus' => 'u_1']), new OrderShipped());
        $this->assertCount(6, $t->requests); // two separate synchronous sends
        $this->assertSame([], $ledger->keys);
    }

    public function testDifferentNotifiablesOfOneNotificationAreTrackedSeparately(): void
    {
        $ledger = new MemoryLedger();
        $t = new FakeTransport();
        $channel = new NexusChannel($this->client($t), $ledger);
        $n = new OrderShipped(['nexus'], 'same-id');
        $channel->send(new class extends FakeNotifiable {
            public function __construct() { parent::__construct(['nexus' => 'a']); }
            public function getKey(): int { return 1; }
        }, $n);
        $channel->send(new class extends FakeNotifiable {
            public function __construct() { parent::__construct(['nexus' => 'b']); }
            public function getKey(): int { return 2; }
        }, $n);
        $this->assertCount(6, $t->requests);
    }

    public function testLegacyToNexusStillWorksThroughNexus(): void
    {
        $t = new FakeTransport();
        $legacy = new class {
            public function toNexus(object $n): NexusMessage // the old class name is an alias
            {
                return NexusMessage::create()->event('order_shipped');
            }
        };
        $results = (new NexusChannel($this->client($t)))->send(new FakeNotifiable(['nexus' => 'u_7']), $legacy);
        $this->assertSame(['realtime'], array_keys($results));
        $this->assertSame('/partner/events', self::paths($t)[0]);
        $this->assertSame('u_7', $t->requests[0]['json']['distinctId']);
        $this->assertInstanceOf(NexusRealtimeMessage::class, NexusMessage::create());
    }

    public function testRealtimeChannelUsesItsOwnRouteFirst(): void
    {
        $t = new FakeTransport();
        $n = new class {
            public function toNexusRealtime(object $n): NexusRealtimeMessage
            {
                return NexusRealtimeMessage::create()->event('x');
            }
        };
        (new NexusRealtimeChannel($this->client($t)))->send(new FakeNotifiable(['nexus-realtime' => 'rt_user', 'nexus' => 'other']), $n);
        $this->assertSame('rt_user', $t->requests[0]['json']['distinctId']);
    }

    public function testANotificationWithNoNexusMethodsSendsNothing(): void
    {
        $t = new FakeTransport();
        $this->assertSame([], (new NexusChannel($this->client($t)))->send(new FakeNotifiable(['nexus' => 'u']), new \stdClass()));
        $this->assertSame([], $t->requests);
    }

    public function testPrivateIdAndRoutesPropertiesNeverBreakIt(): void
    {
        $t = new FakeTransport();
        $notifiable = new class extends FakeNotifiable {
            private array $routes = ['secret'];

            public function __construct() { parent::__construct(['nexus' => 'u_1']); }
        };
        $notification = new class {
            private string $id = 'private-not-a-laravel-id';

            public function toNexusPush(object $n): NexusPushMessage
            {
                return NexusPushMessage::create('Hi');
            }
        };
        $results = (new NexusChannel($this->client($t), new MemoryLedger()))->send($notifiable, $notification);
        $this->assertSame(['push'], array_keys($results));
    }
}

<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\Laravel\Notifications\NexusChannel;
use Inverge\Nexus\Laravel\Notifications\NexusLiveActivityChannel;
use Inverge\Nexus\Laravel\Notifications\NexusLiveActivityMessage;
use Inverge\Nexus\Laravel\Notifications\NexusPushMessage;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Services;
use Inverge\Nexus\Tests\Support\FakeNotifiable;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ServicesTest extends TestCase
{
    /** @param array<string, mixed> $options */
    private function client(FakeTransport $t, array $options = []): NexusClient
    {
        return new NexusClient(Config::fromArray('nxs_test', ['base_url' => 'https://api.example.test'] + $options), $t);
    }

    public function testEverythingIsOnByDefault(): void
    {
        $s = Services::fromEnvironment([]);
        foreach (array_keys(Services::ENV) as $service) {
            $this->assertTrue($s->enabled($service), $service);
        }
    }

    public function testEnvironmentSwitches(): void
    {
        $s = Services::fromEnvironment(['NEXUS_PUSH_ENABLED' => 'false', 'NEXUS_LIVE_ACTIVITIES_ENABLED' => '0', 'NEXUS_EVENTS_ENABLED' => 'true']);
        $this->assertFalse($s->enabled(Services::PUSH));
        $this->assertFalse($s->enabled(Services::LIVE_ACTIVITIES));
        $this->assertTrue($s->enabled(Services::EVENTS));
        $this->assertTrue($s->enabled(Services::REALTIME));
        foreach (['off', 'no', 'FALSE', ' false '] as $v) {
            $this->assertFalse(Services::fromEnvironment(['NEXUS_PUSH_ENABLED' => $v])->enabled(Services::PUSH), $v);
        }
        $this->assertSame('NEXUS_PUSH_ENABLED=false', $s->reason(Services::PUSH));
    }

    public function testMasterSwitch(): void
    {
        $s = Services::fromEnvironment(['NEXUS_ENABLED' => 'false', 'NEXUS_PUSH_ENABLED' => 'true']);
        $this->assertFalse($s->enabled(Services::PUSH));
        $this->assertFalse($s->enabled(Services::REALTIME));
        $this->assertSame('NEXUS_ENABLED=false', $s->reason(Services::PUSH));
    }

    public function testUnknownServiceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Services::fromArray(['puhs' => false]);
    }

    public function testPathMapping(): void
    {
        $this->assertSame(Services::LIVE_ACTIVITIES, Services::forPath('/partner/live-activities/order_1/update'));
        $this->assertSame(Services::REALTIME, Services::forPath('/partner/rooms/orders%3A1/emit'));
        $this->assertSame(Services::REMOTE_CONFIG, Services::forPath('/partner/remote-config/fetch'));
        $this->assertSame(Services::IN_APP, Services::forPath('/partner/inapp/active'));
        $this->assertNull(Services::forPath('/partner/whatever'));
    }

    public function testADisabledServiceMakesNoRequestAndNeverThrows(): void
    {
        $t = new FakeTransport(new ApiResponse(500, '{}')); // would throw if it were called
        $c = $this->client($t, ['services' => ['push' => false, 'live_activities' => false, 'events' => false, 'flags' => false, 'remote_config' => false, 'realtime' => false]]);

        $this->assertSame([], $c->push()->notification()->title('Hi')->toUser('u')->send());
        $this->assertSame([], $c->liveActivities()->template('order_tracking', 'o1')->step('a')->toUser('u')->start());
        $this->assertSame([], $c->liveActivities()->activity('o1')->step('b')->update());
        $this->assertSame(0, $c->events()->capture('x'));
        $this->assertSame([], $c->flags()->evaluate('u')['flags']);
        $this->assertFalse($c->flags()->isEnabled('new_checkout', 'u'));          // off → not enabled
        $this->assertSame('fallback', $c->remoteConfig()->get('banner', 'fallback')); // off → your default
        $this->assertSame([], $c->remoteConfig()->fetch()['parameters']);
        $this->assertSame([], $c->realtime()->emit('orders:1', 'status', ['x' => 1]));
        $this->assertSame([], $t->requests);
        $this->assertFalse($c->enabled(Services::PUSH));
    }

    public function testOtherServicesStillWork(): void
    {
        $t = new FakeTransport(new ApiResponse(200, '{"written":1}'));
        $c = $this->client($t, ['services' => ['push' => false]]);
        $this->assertSame(1, $c->events()->capture('x'));
        $this->assertCount(1, $t->requests);
    }

    public function testMasterSwitchViaConfig(): void
    {
        $t = new FakeTransport();
        $c = $this->client($t, ['enabled' => false]);
        $c->events()->capture('x');
        $c->push()->notification()->title('Hi')->toAll()->send();
        $this->assertSame([], $t->requests);
    }

    public function testLaravelChannelsSkipADisabledProduct(): void
    {
        $t = new FakeTransport();
        $c = $this->client($t, ['services' => ['live_activities' => false]]);
        $n = new class {
            public function toNexusLiveActivity(object $n): NexusLiveActivityMessage
            {
                return NexusLiveActivityMessage::create('o1')->start('order_tracking');
            }

            public function toNexusPush(object $n): NexusPushMessage
            {
                return NexusPushMessage::create('Hi');
            }
        };
        // no route on the notifiable: a disabled channel must not even complain about it
        $this->assertNull((new NexusLiveActivityChannel($c))->send(new FakeNotifiable(), $n));

        $results = (new NexusChannel($c))->send(new FakeNotifiable(['nexus' => 'u_1']), $n);
        $this->assertSame(['push'], array_keys($results)); // push sent, Live Activity skipped
        $this->assertSame('/partner/push/send', parse_url($t->requests[0]['url'], PHP_URL_PATH));
        $this->assertCount(1, $t->requests);
    }
}

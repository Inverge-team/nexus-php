<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\Laravel\Notifications\NexusChannel;
use Inverge\Nexus\Laravel\Notifications\NexusMessagingChannel;
use Inverge\Nexus\Laravel\Notifications\NexusMessagingMessage;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeNotifiable;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class NexusMessagingChannelTest extends TestCase
{
    private function client(FakeTransport $t, array $options = []): NexusClient
    {
        return new NexusClient(Config::fromArray('nxs_test', ['base_url' => 'https://api.example.test'] + $options), $t);
    }

    private static function queued(string $id = 'msg_1'): ApiResponse
    {
        return new ApiResponse(202, '{"id":"'.$id.'","status":"queued"}');
    }

    /** @param mixed $build a closure returning what toNexusMessaging() returns */
    private function notification(\Closure $build, ?string $id = null): object
    {
        return new class($build, $id) {
            public function __construct(private \Closure $build, public ?string $id)
            {
            }

            public function toNexusMessaging(object $n): mixed
            {
                return ($this->build)($n);
            }
        };
    }

    public function testATemplateGoesToTheNotifiablesAddress(): void
    {
        $t = new FakeTransport(self::queued());
        $n = $this->notification(fn () => NexusMessagingMessage::whatsapp()
            ->template('order_shipped', 'en_US', ['order_id' => 'A-91'])
            ->metadata(['order' => 91]));

        $out = (new NexusMessagingChannel($this->client($t)))->send(new FakeNotifiable(['nexus-messaging' => '+9647501234567']), $n);

        $this->assertSame('msg_1', $out[0]['id']);
        $req = $t->lastRequest();
        $this->assertSame('https://api.example.test/partner/messaging/messages', $req['url']);
        $this->assertSame('whatsapp', $req['json']['channel']);
        $this->assertSame('+9647501234567', $req['json']['to']);
        $this->assertSame('order_shipped', $req['json']['templateKey']);
        $this->assertSame('en_US', $req['json']['templateLocale']);
        $this->assertEquals((object) ['order_id' => 'A-91'], $req['json']['templateVariables']);
        $this->assertStringStartsWith('nxs-', $req['headers']['Idempotency-Key']);
    }

    public function testAPerChannelRouteAndAListSendOnEveryChannel(): void
    {
        $t = new FakeTransport(self::queued('msg_1'), self::queued('msg_2'));
        $n = $this->notification(fn () => [
            NexusMessagingMessage::whatsapp('Your order shipped'),
            NexusMessagingMessage::telegram('Your order shipped'),
        ]);
        $notifiable = new FakeNotifiable(['nexus-messaging' => ['whatsapp' => '+9647501234567', 'telegram' => '@alice']]);

        $out = (new NexusMessagingChannel($this->client($t)))->send($notifiable, $n);

        $this->assertSame(['msg_1', 'msg_2'], array_column($out, 'id'));
        $this->assertSame(['whatsapp', '+9647501234567'], [$t->requests[0]['json']['channel'], $t->requests[0]['json']['to']]);
        $this->assertSame(['telegram', '@alice'], [$t->requests[1]['json']['channel'], $t->requests[1]['json']['to']]);
    }

    public function testToOverridesTheRouteAndTheSharedNexusRouteIsNotAnAddress(): void
    {
        $t = new FakeTransport(self::queued());
        $n = $this->notification(fn () => NexusMessagingMessage::sms('Hi')->to('+15550001111')->sender('INVERGE'));
        (new NexusMessagingChannel($this->client($t)))->send(new FakeNotifiable(['nexus' => 'user_7']), $n);
        $this->assertSame(['+15550001111', 'INVERGE'], [$t->lastRequest()['json']['to'], $t->lastRequest()['json']['sender']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('routeNotificationForNexusMessaging');
        $n = $this->notification(fn () => NexusMessagingMessage::sms('Hi'));
        (new NexusMessagingChannel($this->client($t)))->send(new FakeNotifiable(['nexus' => 'user_7']), $n);
    }

    public function testAMissingRecipientSendsNothingAtAll(): void
    {
        $t = new FakeTransport();
        $n = $this->notification(fn () => [NexusMessagingMessage::whatsapp('a'), NexusMessagingMessage::telegram('b')]);
        try {
            (new NexusMessagingChannel($this->client($t)))->send(new FakeNotifiable(['nexus-messaging' => ['whatsapp' => '+964']]), $n);
            $this->fail('expected a LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('telegram', $e->getMessage());
        }
        $this->assertSame([], $t->requests);
    }

    public function testAQueuedNotificationGetsTheSameKeyOnEveryRetry(): void
    {
        $keys = [];
        foreach ([1, 2] as $_) {
            $t = new FakeTransport(self::queued());
            $n = $this->notification(fn () => NexusMessagingMessage::whatsapp('Hi'), 'notif-uuid-1');
            (new NexusMessagingChannel($this->client($t)))->send(new FakeNotifiable(['nexus-messaging' => '+964']), $n);
            $keys[] = $t->lastRequest()['headers']['Idempotency-Key'];
        }
        $this->assertSame($keys[0], $keys[1]);
        $this->assertStringStartsWith('nxs-ntf-', $keys[0]);

        // Without an id (a synchronous send) every send gets a fresh key.
        $t = new FakeTransport(self::queued(), self::queued());
        $n = $this->notification(fn () => NexusMessagingMessage::whatsapp('Hi'));
        $channel = new NexusMessagingChannel($this->client($t));
        $channel->send(new FakeNotifiable(['nexus-messaging' => '+964']), $n);
        $channel->send(new FakeNotifiable(['nexus-messaging' => '+964']), $n);
        $this->assertNotSame($t->requests[0]['headers']['Idempotency-Key'], $t->requests[1]['headers']['Idempotency-Key']);

        // The caller's own key wins.
        $n = $this->notification(fn () => NexusMessagingMessage::whatsapp('Hi')->idempotencyKey('order-91'), 'notif-uuid-1');
        $channel->send(new FakeNotifiable(['nexus-messaging' => '+964']), $n);
        $this->assertSame('order-91', $t->lastRequest()['headers']['Idempotency-Key']);
    }

    public function testAFailureStillAttemptsTheOthersThenThrows(): void
    {
        $t = new FakeTransport(
            new ApiResponse(402, '{"error":{"code":"insufficient_balance","message":"Top up your wallet."}}'),
            self::queued('msg_2'),
        );
        $n = $this->notification(fn () => [NexusMessagingMessage::whatsapp('a'), NexusMessagingMessage::telegram('b')]);
        try {
            (new NexusMessagingChannel($this->client($t)))->send(new FakeNotifiable(['nexus-messaging' => ['whatsapp' => '+964', 'telegram' => '@a']]), $n);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame('insufficient_balance', $e->errorCode);
        }
        $this->assertCount(2, $t->requests);
    }

    public function testNullSkipsAndSwitchedOffSendsNothing(): void
    {
        $t = new FakeTransport();
        $this->assertNull((new NexusMessagingChannel($this->client($t)))->send(new FakeNotifiable(), $this->notification(fn () => null)));

        $called = false;
        $n = $this->notification(function () use (&$called) {
            $called = true;

            return NexusMessagingMessage::sms('Hi')->to('+1');
        });
        $off = $this->client($t, ['services' => ['messaging' => false]]);
        $this->assertNull((new NexusMessagingChannel($off))->send(new FakeNotifiable(), $n));
        $this->assertFalse($called);
        $this->assertSame([], $t->requests);
    }

    public function testTheWrongReturnTypeIsReported(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new NexusMessagingChannel($this->client(new FakeTransport())))->send(new FakeNotifiable(), $this->notification(fn () => 'hello'));
    }

    public function testTheAllInOneChannelSendsMessagingToo(): void
    {
        $t = new FakeTransport(self::queued());
        $n = $this->notification(fn () => NexusMessagingMessage::telegram('Shipped'));

        $results = (new NexusChannel($this->client($t)))->send(new FakeNotifiable(['nexus-messaging' => '@alice']), $n);

        $this->assertSame('msg_1', $results['messaging'][0]['id']);
        $this->assertSame('@alice', $t->lastRequest()['json']['to']);
    }
}

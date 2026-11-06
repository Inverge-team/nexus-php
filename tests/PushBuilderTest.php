<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\Laravel\Notifications\NexusPushChannel;
use Inverge\Nexus\Laravel\Notifications\NexusPushMessage;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeNotifiable;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class PushBuilderTest extends TestCase
{
    /** @return array{0: NexusClient, 1: FakeTransport} */
    private function make(string ...$replies): array
    {
        $replies = $replies ?: ['{"sent":1,"failed":0,"recipients":1}'];
        $transport = new FakeTransport(...array_map(static fn ($r) => new ApiResponse(200, $r), $replies));

        return [new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport), $transport];
    }

    public function testWhenAndUnlessKeepTheChain(): void
    {
        [$client] = $this->make();
        $ids = [];
        $payload = $client->push()->notification()
            ->title('Hi')
            ->when('https://cdn/a.png', fn ($p, $url) => $p->image($url))
            ->when(null, fn ($p) => $p->body('never'))
            ->when($ids, fn ($p, $v) => $p->toUsers($v), fn ($p) => $p->toSegment('All Clients'))
            ->when(fn () => true, fn ($p) => $p->data(['lazy' => 1]))
            ->unless(false, fn ($p) => $p->iosBadge(1))
            ->toArray();

        $this->assertSame([
            'title' => 'Hi',
            'imageUrl' => 'https://cdn/a.png',
            'data' => ['lazy' => 1],
            'options' => ['iosBadge' => 1],
            'segmentIds' => ['All Clients'],
        ], $payload);
    }

    public function testEmptyOptionalValuesAreNoOps(): void
    {
        [$client] = $this->make();
        $payload = $client->push()->notification()->title('Hi')
            ->image(null)->body('')->toSegment('')->toUsers(['', 'u1'])->sendAt(null)->toArray();

        $this->assertSame(['title' => 'Hi', 'distinctIds' => ['u1']], $payload);
    }

    public function testSendAtSchedulesWithAnAbsoluteInstant(): void
    {
        [$client, $transport] = $this->make('{"id":"c1","status":"scheduled","scheduledAt":"2026-11-07T09:00:00.000Z","sentAt":null,"recipients":0,"sent":0,"failed":0}');
        $res = $client->push()->notification()->title('Hi')->toUser('u1')->name('Morning')
            ->schedule(new \DateTimeImmutable('2026-11-07 12:00', new \DateTimeZone('Asia/Baghdad')));

        $this->assertSame('c1', $res['id']);
        $req = $transport->lastRequest();
        $this->assertSame('2026-11-07T09:00:00Z', $req['json']['sendAt']);
        $this->assertSame('Morning', $req['json']['name']);
        $this->assertSame('1970-01-01T00:00:00Z', $client->push()->notification()->sendAt(0)->toArray()['sendAt']);

        $this->expectException(\InvalidArgumentException::class);
        $client->push()->notification()->sendAt('2026-11-07 09:00');
    }

    public function testScheduledAndCancel(): void
    {
        [$client, $transport] = $this->make('{"id":"c1","status":"scheduled"}', '{"id":"c1","status":"canceled"}');
        $client->push()->scheduled('c/1');
        $this->assertSame('GET', $transport->lastRequest()['method']);
        $this->assertSame('https://api.example.test/partner/push/scheduled/c%2F1', $transport->lastRequest()['url']);
        $this->assertSame('canceled', $client->push()->cancelScheduled('c1')['status']);
        $this->assertSame('https://api.example.test/partner/push/scheduled/c1/cancel', $transport->lastRequest()['url']);
    }

    public function testNotificationMessageRecordsWhenCalls(): void
    {
        [$client, $transport] = $this->make();
        $ids = [];
        $n = new class($ids) {
            public function __construct(private array $ids)
            {
            }

            public function toNexusPush(object $n): NexusPushMessage
            {
                return NexusPushMessage::create('Hi')
                    ->image(null)
                    ->when($this->ids === [], fn ($m) => $m->toSegment('All Clients'), fn ($m) => $m->toUsers($this->ids))
                    ->sendAt('2026-11-07T09:00:00Z');
            }
        };

        (new NexusPushChannel($client))->send(new FakeNotifiable(['nexus' => 'CLIENTS.1']), $n);
        $json = $transport->lastRequest()['json'];
        $this->assertSame(['All Clients'], $json['segmentIds']);
        $this->assertArrayNotHasKey('distinctIds', $json); // the message targeted itself
        $this->assertSame('2026-11-07T09:00:00Z', $json['sendAt']);
    }

    public function testTitleAndBodyPerLanguage(): void
    {
        [$client] = $this->make();
        $payload = $client->push()->notification()
            ->title(['en' => 'Your order shipped', 'ar' => 'تم شحن طلبك', 'ckb' => ''])
            ->body(['en' => 'Track it in the app', 'ar' => '', 'ckb' => 'بەدوایدا بچۆ'])
            ->toUser('u1')
            ->toArray();

        $this->assertSame('Your order shipped', $payload['title']);
        $this->assertSame('Track it in the app', $payload['body']);
        $this->assertSame('en', $payload['defaultLanguage']);
        $this->assertSame([
            'en' => ['title' => 'Your order shipped', 'body' => 'Track it in the app'],
            'ar' => ['title' => 'تم شحن طلبك', 'body' => 'Track it in the app'],   // empty body → default's
            'ckb' => ['title' => 'Your order shipped', 'body' => 'بەدوایدا بچۆ'],  // empty title → default's
        ], $payload['localizations']);
    }

    public function testExplicitDefaultLanguageAndPlainSend(): void
    {
        [$client, $transport] = $this->make();
        $payload = $client->push()->notification()
            ->title(['ar' => 'مرحبا', 'ckb' => 'سڵاو'])->defaultLanguage('ckb')->toUser('u')->toArray();
        $this->assertSame('سڵاو', $payload['title']);
        $this->assertSame('ckb', $payload['defaultLanguage']);
        $this->assertArrayNotHasKey('body', $payload);

        $client->push()->send(['u1'], ['title' => ['en' => 'Hi', 'ar' => 'مرحبا']]);
        $json = $transport->lastRequest()['json'];
        $this->assertSame('Hi', $json['title']);
        $this->assertSame(['title' => 'مرحبا'], $json['localizations']['ar']);
        $this->assertSame(['u1'], $json['distinctIds']);

        // A plain string still sends exactly as before.
        $this->assertSame(['title' => 'Hi', 'distinctIds' => ['u']], $client->push()->notification()->title('Hi')->toUser('u')->toArray());

        $this->expectException(\InvalidArgumentException::class);
        $client->push()->notification()->title(['en' => '', 'ar' => ''])->toUser('u')->send(); // nothing left
    }

    public function testNotificationMessageAcceptsTranslations(): void
    {
        [$client, $transport] = $this->make();
        $n = new class() {
            public function toNexusPush(object $n): NexusPushMessage
            {
                return NexusPushMessage::create(['en' => 'Shipped', 'ar' => 'تم الشحن'], ['en' => 'Track it']);
            }
        };
        (new NexusPushChannel($client))->send(new FakeNotifiable(['nexus' => 'CLIENTS.1']), $n);
        $json = $transport->lastRequest()['json'];
        $this->assertSame('Shipped', $json['title']);
        $this->assertSame(['title' => 'تم الشحن', 'body' => 'Track it'], $json['localizations']['ar']);
    }
}

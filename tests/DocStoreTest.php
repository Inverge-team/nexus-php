<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\DocStore\DocStore;
use Inverge\Nexus\DocStore\FieldValue;
use Inverge\Nexus\DocStore\Filter;
use Inverge\Nexus\DocStore\ServiceDisabledException;
use Inverge\Nexus\DocStore\Webhook;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\NexusException;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class DocStoreTest extends TestCase
{
    private const SK = 'nxsk_abc123abc123_secret';
    private const DOC = '{"id":"o1","data":{"total":5},"version":1,"createdAt":"2026-10-28T00:00:00.000Z","updatedAt":"2026-10-28T00:00:00.000Z"}';

    private function client(FakeTransport $t, array $options = []): NexusClient
    {
        return new NexusClient(Config::fromArray('nxs_test', ['base_url' => 'https://api.example.test'] + $options), $t);
    }

    public function testFieldValuesCompileToOperators(): void
    {
        $this->assertSame(
            ['$inc' => ['views' => 2], '$addToSet' => ['tags' => ['$each' => ['a']]], '$serverTime' => ['at'], '$unset' => ['draft'], '$set' => ['a.b' => 1]],
            FieldValue::toSpec(['views' => FieldValue::increment(2), 'tags' => FieldValue::arrayUnion('a'), 'at' => FieldValue::serverTimestamp(), 'draft' => FieldValue::delete(), 'a.b' => 1]),
        );
    }

    public function testQueryBodyAndServiceKeyHeader(): void
    {
        $t = new FakeTransport(new ApiResponse(200, '{"items":[' . self::DOC . '],"nextCursor":"c1","hasMore":true,"total":9}'));
        $page = $this->client($t)->docstore(self::SK)->collection('orders')
            ->where('status', 'in', ['open', 'paid'])
            ->where(Filter::or(Filter::where('total', '>=', 100), Filter::where('vip', '==', true)))
            ->orderBy('total', 'desc')
            ->limit(20)
            ->withCount()
            ->get();
        $req = $t->lastRequest();
        $this->assertSame('https://api.example.test/partner/docstore/collections/orders/query', $req['url']);
        $this->assertSame(self::SK, $req['headers']['x-nexus-service-key']);
        $this->assertSame([
            'filter' => ['$and' => [['status' => ['$in' => ['open', 'paid']]], ['$or' => [['total' => ['$gte' => 100]], ['vip' => ['$eq' => true]]]]]],
            'sort' => [['field' => 'total', 'dir' => 'desc']],
            'limit' => 20,
            'count' => true,
        ], $req['json']);
        $this->assertSame(5, $page->docs[0]->get('total'));
        $this->assertSame(['c1', 9], [$page->nextCursor, $page->total]);
    }

    public function testAsUserSendsBearerAndMissingReadsAsNotExists(): void
    {
        $t = new FakeTransport(new ApiResponse(404, '{"error":{"code":"not_found","message":"gone"}}'));
        $snap = $this->client($t)->docstore(self::SK)->asUser('user-jwt')->doc('orders/nope')->get(['user']);
        $this->assertFalse($snap->exists());
        $req = $t->lastRequest();
        $this->assertSame('Bearer user-jwt', $req['headers']['Authorization']);
        $this->assertArrayNotHasKey('x-nexus-service-key', $req['headers']);
        $this->assertStringContainsString('expand=user', $req['url']);
    }

    public function testEmptyDataIsSentAsAnObject(): void
    {
        $t = new FakeTransport(new ApiResponse(201, '{"index":0,"status":"ok","id":"x","doc":' . self::DOC . '}'));
        $this->client($t)->docstore(self::SK)->collection('c')->add([], 'x');
        $this->assertStringContainsString('"data":{}', json_encode($t->lastRequest()['json']));
    }

    public function testTransactionRetriesAfterARace(): void
    {
        $conflict = new ApiResponse(409, '{"error":{"code":"conflict","message":"precondition","details":{"reason":"precondition_failed"}}}');
        $ok = new ApiResponse(200, '{"results":[{"index":0,"status":"ok","collection":"accounts","id":"a","version":5}]}');
        $read = fn (int $v, int $b) => new ApiResponse(200, json_encode(['id' => 'a', 'data' => ['balance' => $b], 'version' => $v, 'createdAt' => 'x', 'updatedAt' => 'x']));
        $t = new FakeTransport($read(3, 100), $conflict, $read(4, 105), $ok);
        $db = $this->client($t)->docstore(self::SK);
        $ref = $db->doc('accounts/a');
        $attempts = 0;
        $out = $db->runTransaction(function ($tx) use ($ref, &$attempts) {
            $attempts++;
            $s = $tx->get($ref);
            $tx->set($ref, ['balance' => $s->get('balance') - 40]);
            return 'done';
        });
        $this->assertSame(['done', 2], [$out, $attempts]);
        $last = $t->lastRequest()['json']['operations'][0];
        $this->assertSame(['version' => 4], $last['precondition']);
        $this->assertSame(65, $last['data']['balance']);
    }

    public function testErrorsThrowAndPreconditionIsDetected(): void
    {
        $t = new FakeTransport(new ApiResponse(409, '{"error":{"code":"conflict","message":"x","details":{"reason":"precondition_failed"}}}'));
        try {
            $this->client($t)->docstore(self::SK)->doc('a/b')->set(['x' => 1], ifVersion: 1);
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertTrue(DocStore::isPreconditionFailed($e));
        }
    }

    public function testSwitchedOffThrowsWithoutARequest(): void
    {
        $t = new FakeTransport();
        $this->expectException(ServiceDisabledException::class);
        try {
            $this->client($t, ['services' => ['docstore' => false]])->docstore(self::SK)->doc('a/b')->get();
        } finally {
            $this->assertSame([], $t->requests);
        }
    }

    public function testChangeStreamResumesFromNext(): void
    {
        $t = new FakeTransport(
            new ApiResponse(200, '{"changes":[{"seq":4,"collection":"c","id":"x","op":"create"}],"next":4,"hasMore":true}'),
            new ApiResponse(200, '{"changes":[{"seq":5,"collection":"c","id":"y","op":"delete"}],"next":5,"hasMore":false}'),
        );
        $seen = [];
        foreach ($this->client($t)->docstore(self::SK)->watchChanges(3, null, 0.0, function () use (&$seen) { return count($seen) >= 2; }) as $c) {
            $seen[] = $c['seq'];
        }
        $this->assertSame([4, 5], $seen);
        $this->assertStringContainsString('since=3', $t->requests[0]['url']);
        $this->assertStringContainsString('since=4', $t->requests[1]['url']);
    }

    public function testWebhookVerification(): void
    {
        $body = '{"event":"docstore.document.create","id":"o1"}';
        $ts = (string) time();
        $headers = ['X-Nexus-Signature' => hash_hmac('sha256', "{$ts}.n1.{$body}", 'whsec_x'), 'X-Nexus-Timestamp' => $ts, 'X-Nexus-Nonce' => 'n1'];
        $this->assertSame('o1', Webhook::verify($body, $headers, 'whsec_x')['id']);
        $this->expectException(NexusException::class);
        Webhook::verify($body . ' ', $headers, 'whsec_x');
    }

    public function testAnUnfilteredQuerySendsAnObjectBody(): void
    {
        $t = new FakeTransport(new ApiResponse(200, '{"items":[],"nextCursor":null,"hasMore":false}'));
        $this->client($t)->docstore(self::SK)->collection('orders')->get();
        $this->assertSame('{"filter":{}}', json_encode($t->lastRequest()['json']));
    }

    public function testRejectsANonServiceKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new FakeTransport())->docstore('nxs_public_key');
    }
}

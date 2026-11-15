<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\PushAuth\PushAuth;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class PushAuthTest extends TestCase
{
    private const PENDING = '{"id":"pac_1","status":"pending","stage":null,"method":"number_match","requesterView":{"number":"42"}}';

    private function pa(Transport $t, array $options = []): PushAuth
    {
        return (new NexusClient(Config::fromArray('nxs_test', ['base_url' => 'https://api.example.test'] + $options), $t))->pushAuth();
    }

    public function testCreateChallengeSendsWireFields(): void
    {
        $t = new FakeTransport(new ApiResponse(201, self::PENDING));
        $c = $this->pa($t)->createChallenge('user_1', 'Sign in to Acme', method: 'number_match',
            fields: [['label' => 'Amount', 'value' => '25']], context: ['device' => 'Chrome'], fallbackTo: '+9647501234567');
        $this->assertSame('42', $c['requesterView']['number']);
        $req = $t->lastRequest();
        $this->assertSame(['POST', 'https://api.example.test/partner/push-auth/challenges'], [$req['method'], $req['url']]);
        $this->assertArrayNotHasKey('X-Nexus-Secret', $req['headers']);
        $this->assertSame(
            ['identity' => 'user_1', 'details' => ['message' => 'Sign in to Acme', 'fields' => [['label' => 'Amount', 'value' => '25']], 'context' => ['device' => 'Chrome']], 'method' => 'number_match', 'fallback' => ['to' => '+9647501234567']],
            $req['json'],
        );
    }

    public function testWaitLongPollsBelowTheClientTimeout(): void
    {
        $t = new FakeTransport(new ApiResponse(200, self::PENDING), new ApiResponse(200, self::PENDING), new ApiResponse(200, '{"id":"pac_1","status":"approved","receipt":"a.b.c"}'));
        $done = $this->pa($t, ['timeout' => 30])->waitForChallenge('pac_1', 60);
        $this->assertSame('approved', $done['status']);
        $this->assertMatchesRegularExpression('#/partner/push-auth/challenges/pac_1\?wait=(\d+)$#', $t->requests[1]['url']);
        preg_match('#wait=(\d+)#', $t->requests[1]['url'], $m);
        $this->assertLessThanOrEqual(25, (int) $m[1]);
    }

    public function testErrorsCarryTheServerCode(): void
    {
        $t = new FakeTransport(new ApiResponse(423, '{"error":{"code":"identity_locked","message":"locked"}}'));
        try {
            $this->pa($t)->createChallenge('u', 'm');
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('identity_locked', $e->errorCode);
        }
    }

    public function testWebhookSignature(): void
    {
        $body = '{"id":"pawh_1"}';
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.n1.' . $body, 'whsec_x');
        $this->assertTrue(PushAuth::verifyWebhook($body, $sig, $ts, 'n1', 'whsec_x'));
        $this->assertFalse(PushAuth::verifyWebhook($body . ' ', $sig, $ts, 'n1', 'whsec_x'));
        $this->assertFalse(PushAuth::verifyWebhook($body, $sig, $ts - 1000, 'n1', 'whsec_x'));
    }
}

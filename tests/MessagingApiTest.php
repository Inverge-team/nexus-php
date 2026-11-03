<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\NexusException;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class MessagingApiTest extends TestCase
{
    private function client(Transport $t, array $options = []): NexusClient
    {
        return new NexusClient(Config::fromArray('nxs_test', ['base_url' => 'https://api.example.test'] + $options), $t);
    }

    private static function wire(?array $json): string
    {
        return (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function testWhatsAppTemplateSendUsesTheApiFieldNames(): void
    {
        $t = new FakeTransport(new ApiResponse(202, '{"id":"msg_1","status":"queued"}'));
        $out = $this->client($t)->messaging()->send('whatsapp', '+9647501234567',
            template: 'order_shipped', language: 'en_US', variables: ['order_id' => 'A-91', 'n' => 3]);

        $this->assertSame('msg_1', $out['id']);
        $req = $t->lastRequest();
        $this->assertSame(['POST', 'https://api.example.test/partner/messaging/messages'], [$req['method'], $req['url']]);
        $this->assertSame(
            '{"channel":"whatsapp","to":"+9647501234567","templateKey":"order_shipped","templateLocale":"en_US","templateVariables":{"order_id":"A-91","n":"3"}}',
            self::wire($req['json']),
        );
        $this->assertMatchesRegularExpression('/^nxs-[0-9a-f]{32}$/', $req['headers']['Idempotency-Key']);
    }

    public function testContentPassesThroughAndEmptyObjectsStayObjects(): void
    {
        $t = new FakeTransport();
        $this->client($t)->messaging()->send('telegram', '@alice', body: 'caption',
            content: ['type' => 'photo', 'media' => 'tgf_abcdefghijkl', 'replyMarkup' => ['inline_keyboard' => []]],
            metadata: [], trackLinks: false, idempotencyKey: 'order-91');
        $req = $t->lastRequest();
        $this->assertSame(
            '{"channel":"telegram","to":"@alice","body":"caption","content":{"type":"photo","media":"tgf_abcdefghijkl","replyMarkup":{"inline_keyboard":[]}},"metadata":{},"trackLinks":false}',
            self::wire($req['json']),
        );
        $this->assertSame('order-91', $req['headers']['Idempotency-Key']);
    }

    public function testScheduledAtAcceptsADateTime(): void
    {
        $t = new FakeTransport();
        $this->client($t)->messaging()->send('sms', '+1', body: 'x', scheduledAt: new \DateTimeImmutable('2026-01-02T03:04:00+00:00'));
        $this->assertSame('2026-01-02T03:04:00+00:00', $t->lastRequest()['json']['scheduledAt']);
    }

    public function testRefusesASendWithNothingToSend(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new FakeTransport())->messaging()->send('whatsapp', '+1');
    }

    public function testRejectionThrowsWithTheApiCode(): void
    {
        $t = new FakeTransport(new ApiResponse(402, '{"error":{"code":"insufficient_balance","message":"Top up your wallet."}}'));
        try {
            $this->client($t)->messaging()->send('whatsapp', '+1', body: 'x');
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(['insufficient_balance', 402], [$e->errorCode, $e->status]);
        }
    }

    public function testGetAndEvents(): void
    {
        $t = new FakeTransport(
            new ApiResponse(200, '{"id":"msg 1","status":"delivered"}'),
            new ApiResponse(200, '{"events":[{"type":"delivered"}]}'),
        );
        $m = $this->client($t)->messaging();
        $this->assertSame('delivered', $m->get('msg 1')['status']);
        $this->assertSame([['type' => 'delivered']], $m->events('msg 1'));
        $this->assertSame(
            ['https://api.example.test/partner/messaging/messages/msg%201', 'https://api.example.test/partner/messaging/messages/msg%201/events'],
            array_column($t->requests, 'url'),
        );
    }

    public function testUploadsAreRawBodies(): void
    {
        $t = new FakeTransport(new ApiResponse(201, '{"mediaId":"123"}'), new ApiResponse(201, '{"uploadId":"tgf_x"}'));
        $m = $this->client($t)->messaging();
        $this->assertSame('123', $m->uploadMediaBytes('%PDF-1.7', 'application/pdf', 'invoice 1.pdf')['mediaId']);

        // Only a PNG signature: content sniffing can't name it, so the type must come from the extension.
        $file = tempnam(sys_get_temp_dir(), 'nx') . '.png';
        file_put_contents($file, "\x89PNG\r\n\x1a\n");
        $m->uploadTelegramFile($file);
        unlink($file);

        [$wa, $tg] = $t->requests;
        $this->assertSame('https://api.example.test/partner/messaging/media?filename=invoice%201.pdf', $wa['url']);
        $this->assertSame(['application/pdf', '%PDF-1.7'], [$wa['headers']['Content-Type'], $wa['body']]);
        $this->assertStringStartsWith('https://api.example.test/partner/messaging/telegram/uploads?filename=', $tg['url']);
        $this->assertSame('image/png', $tg['headers']['Content-Type']);
    }

    public function testUploadNeedsARawCapableTransport(): void
    {
        $plain = new class implements Transport {
            public function send(string $method, string $url, array $headers, ?array $json, float $timeout): ApiResponse
            {
                return new ApiResponse(200, '{}');
            }
        };
        $this->expectException(NexusException::class);
        $this->client($plain)->messaging()->uploadMediaBytes('x', 'image/jpeg');
    }

    public function testVerifications(): void
    {
        $t = new FakeTransport(
            new ApiResponse(201, '{"id":"ver_1","status":"pending"}'),
            new ApiResponse(200, '{"id":"ver_1","valid":true,"attemptsRemaining":4}'),
        );
        $v = $this->client($t)->messaging()->verifications();
        $started = $v->start('+9647501234567', codeLength: 6, smsFallback: true);
        $this->assertTrue($v->check($started['id'], '123456')['valid']);
        $v->cancel('ver_1');

        [$start, $check, $cancel] = $t->requests;
        $this->assertSame('{"to":"+9647501234567","codeLength":6,"fallback":"sms"}', self::wire($start['json']));
        $this->assertSame(['https://api.example.test/partner/messaging/verifications/ver_1/check', ['code' => '123456']], [$check['url'], $check['json']]);
        $this->assertSame(['https://api.example.test/partner/messaging/verifications/ver_1/cancel', null], [$cancel['url'], $cancel['json']]);
    }

    public function testVerificationChannelPlan(): void
    {
        $t = new FakeTransport();
        $this->client($t)->messaging()->verifications()->start('+9647501234567',
            channels: ['telegram', 'whatsapp'], whatsappTemplate: 'acme_login', whatsappLanguage: 'ar');
        $this->assertSame(
            '{"to":"+9647501234567","channels":["telegram","whatsapp"],"whatsappTemplate":"acme_login","whatsappLanguage":"ar"}',
            self::wire($t->lastRequest()['json']),
        );
    }

    public function testSwitchedOffMakesNoCall(): void
    {
        $t = new FakeTransport();
        $m = $this->client($t, ['services' => ['messaging' => false]])->messaging();
        $this->assertNull($m->send('whatsapp', '+1', body: 'x'));
        $this->assertNull($m->uploadMediaBytes('x', 'image/jpeg'));
        $this->assertNull($m->verifications()->start('+1'));
        $this->assertSame([], $t->requests);
    }
}

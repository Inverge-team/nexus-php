<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Resource\Shield;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ShieldTest extends TestCase
{
    public function testVerifyRequestReadsHeadersAndHashesTheBody(): void
    {
        $transport = new FakeTransport(new ApiResponse(200, '{"valid":true,"decision":"allow","mode":"enforce","reason":null,"resource":"checkout","policy":"checkout","token":{"score":91}}'));
        $client = new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport);

        $v = $client->shield()->verifyRequest(['X-Nexus-Shield' => ['DPoP tok'], 'DPoP' => 'proof'], 'post', 'https://shop.test/checkout', '{"total":42}', 'checkout');

        $this->assertSame('allow', $v['decision']);
        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/shield/verify', $req['url']);
        $this->assertSame([
            'token' => 'tok',
            'proof' => 'proof',
            'method' => 'POST',
            'url' => 'https://shop.test/checkout',
            'resource' => 'checkout',
            'bodyHash' => rtrim(strtr(base64_encode(hash('sha256', '{"total":42}', true)), '+/', '-_'), '='),
        ], $req['json']);
    }

    public function testMissingTokenNeverCallsTheApi(): void
    {
        $transport = new FakeTransport();
        $client = new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport);
        $v = $client->shield()->verify(null, null, 'GET', 'https://a/b');
        $this->assertSame('token_missing', $v['reason']);
        $this->assertSame([], $transport->requests);
    }

    public function testBodyHash(): void
    {
        $this->assertNull(Shield::bodyHash(null));
        $this->assertSame('47DEQpj8HBSa-_TImW-5JCeuQeRkm5NMpJWZG3hSuFU', Shield::bodyHash(''));
    }
}

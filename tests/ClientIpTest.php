<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Laravel\ClientIpPropagation;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Support\ClientIp;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * The end user's IP reaching Messaging's per-IP rate limit — directly, and
 * through a queued Laravel notification that starts the verification on a
 * worker (the request is long gone by then).
 */
final class ClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        ClientIp::clear();
        ClientIp::enable();
    }

    protected function tearDown(): void
    {
        ClientIp::clear();
        ClientIp::enable();
    }

    private function client(FakeTransport $t): NexusClient
    {
        return new NexusClient(Config::fromArray('nxs_test', ['base_url' => 'https://api.example.test']), $t);
    }

    public function testVerificationForwardsTheRequestsAddress(): void
    {
        $t = new FakeTransport();
        ClientIp::set('203.0.113.42');
        $this->client($t)->messaging()->verifications()->start('+9647501234567');

        $req = $t->lastRequest();
        $this->assertSame('203.0.113.42', $req['headers']['X-Client-IP']);
        $this->assertArrayNotHasKey('clientIp', $req['json']);
    }

    public function testAnExplicitAddressGoesInTheBodyAndWins(): void
    {
        $t = new FakeTransport();
        ClientIp::set('203.0.113.42');
        $this->client($t)->messaging()->verifications()->start('+9647501234567', clientIp: '198.51.100.7');

        $req = $t->lastRequest();
        $this->assertSame('198.51.100.7', $req['json']['clientIp']);
        $this->assertArrayNotHasKey('X-Client-IP', $req['headers']);
    }

    public function testOnlyOtpSendsPickTheAddressUpByThemselves(): void
    {
        $t = new FakeTransport();
        ClientIp::set('203.0.113.42');
        $m = $this->client($t)->messaging();

        // A send triggered by, say, a payment webhook must not be counted
        // against the webhook sender's address.
        $m->send('sms', '+9647501234567', body: 'Your order shipped');
        $this->assertArrayNotHasKey('X-Client-IP', $t->lastRequest()['headers']);

        $m->send('sms', '+9647501234567', body: 'Code 123456', messageClass: 'otp');
        $this->assertSame('203.0.113.42', $t->lastRequest()['headers']['X-Client-IP']);
    }

    public function testProxyAndPrivateAddressesAreNeverForwarded(): void
    {
        foreach (['10.0.4.12', '172.16.0.9', '192.168.1.20', '127.0.0.1', '::1', 'fd00::1', 'not-an-ip'] as $ip) {
            ClientIp::set($ip);
            $this->assertNull(ClientIp::forwarded(), $ip);
        }
        ClientIp::set('2a00:1450:4001:81b::200e');
        $this->assertSame('2a00:1450:4001:81b::200e', ClientIp::forwarded());
    }

    public function testDisableStopsForwarding(): void
    {
        $t = new FakeTransport();
        ClientIp::set('203.0.113.42');
        ClientIp::disable();
        $this->client($t)->messaging()->verifications()->start('+9647501234567');
        $this->assertArrayNotHasKey('X-Client-IP', $t->lastRequest()['headers']);
    }

    public function testRunScopesAnAddressAndRestoresTheOuterOne(): void
    {
        ClientIp::set('203.0.113.42');
        $inner = ClientIp::run(null, static fn () => ClientIp::forwarded());
        $this->assertNull($inner);
        $this->assertSame('203.0.113.42', ClientIp::forwarded());
    }

    public function testCliHasNoAddressUntilOneIsSet(): void
    {
        $this->assertNull(ClientIp::current()); // PHPUnit runs in the CLI: no REMOTE_ADDR fallback
    }

    // ── Laravel: request → queued notification → worker ──────────────────

    public function testAQueuedNotificationStartsTheVerificationWithTheRequestersAddress(): void
    {
        [$events, $payloadHooks] = $this->laravel();

        // 1. The HTTP request: the route matches (TrustProxies has run).
        $events->fire('Illuminate\Routing\Events\RouteMatched', (object) ['request' => new FakeRequest('203.0.113.42')]);
        // 2. Notification::send(...) on a ShouldQueue notification builds the job payload.
        $payload = ['displayName' => 'Illuminate\Notifications\SendQueuedNotifications'];
        foreach ($payloadHooks as $hook) {
            $payload += $hook('sqs', 'notifier', $payload);
        }
        $this->assertSame('203.0.113.42', $payload['nexusClientIp']);

        // 3. A queue worker — another process, no request.
        ClientIp::clear();
        $t = new FakeTransport();
        $job = new FakeJob($payload);
        $events->fire('Illuminate\Queue\Events\JobProcessing', (object) ['job' => $job]);
        $this->client($t)->messaging()->verifications()->start('+9647501234567', channels: ['whatsapp']);
        $events->fire('Illuminate\Queue\Events\JobProcessed', (object) ['job' => $job]);

        $this->assertSame('203.0.113.42', $t->lastRequest()['headers']['X-Client-IP']);
        $this->assertNull(ClientIp::current(), 'the next job must not inherit it');
    }

    public function testAJobDispatchedWithoutARequestCarriesNoAddress(): void
    {
        [$events, $payloadHooks] = $this->laravel();
        $payload = [];
        foreach ($payloadHooks as $hook) {
            $payload += $hook('sqs', 'default', $payload);
        }
        $this->assertArrayNotHasKey('nexusClientIp', $payload);

        $t = new FakeTransport();
        $job = new FakeJob($payload);
        $events->fire('Illuminate\Queue\Events\JobProcessing', (object) ['job' => $job]);
        $this->client($t)->messaging()->verifications()->start('+9647501234567');
        $this->assertArrayNotHasKey('X-Client-IP', $t->lastRequest()['headers']);
    }

    public function testTheSyncDriverHandsTheRequestItsAddressBack(): void
    {
        [$events] = $this->laravel();
        $events->fire('Illuminate\Routing\Events\RouteMatched', (object) ['request' => new FakeRequest('203.0.113.42')]);

        $job = new FakeJob([]); // e.g. a job dispatched from a command, run inline
        $events->fire('Illuminate\Queue\Events\JobProcessing', (object) ['job' => $job]);
        $this->assertNull(ClientIp::current());
        $events->fire('Illuminate\Queue\Events\JobExceptionOccurred', (object) ['job' => $job]);

        $this->assertSame('203.0.113.42', ClientIp::current());
    }

    public function testWarnsOnceWhenTheProxyIsNotTrusted(): void
    {
        $warnings = [];
        [$events] = $this->laravel(static function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        });
        $untrusted = new FakeRequest('198.51.100.1', ['X-Forwarded-For' => '203.0.113.42'], '198.51.100.1');
        $events->fire('Illuminate\Routing\Events\RouteMatched', (object) ['request' => $untrusted]);
        $events->fire('Illuminate\Routing\Events\RouteMatched', (object) ['request' => $untrusted]);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('trusted proxies', $warnings[0]);

        // Trusted: ip() resolved the forwarded address — nothing to say.
        $warnings = [];
        [$events] = $this->laravel(static function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        });
        $events->fire('Illuminate\Routing\Events\RouteMatched', (object) [
            'request' => new FakeRequest('203.0.113.42', ['X-Forwarded-For' => '203.0.113.42'], '198.51.100.1'),
        ]);
        $this->assertSame([], $warnings);
    }

    /** @return array{FakeEvents, list<callable>} */
    private function laravel(?\Closure $warn = null): array
    {
        $events = new FakeEvents();
        $hooks = new \ArrayObject();
        (new ClientIpPropagation(
            $warn ?? static function (): void {
            },
            static function (callable $c) use ($hooks): void {
                $hooks[] = $c;
            },
        ))->register($events);

        return [$events, $hooks->getArrayCopy()];
    }
}

final class FakeEvents
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function fire(string $event, object $payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $l) {
            $l($payload);
        }
    }
}

final class FakeRequest
{
    /** @param array<string, string> $headers */
    public function __construct(private string $ip, private array $headers = [], private ?string $remote = null)
    {
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function server(string $key): ?string
    {
        return $key === 'REMOTE_ADDR' ? ($this->remote ?? $this->ip) : null;
    }
}

final class FakeJob
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload)
    {
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}

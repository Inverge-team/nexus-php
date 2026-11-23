<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Inverge\Nexus\Support\ClientIp;

/**
 * Gives {@see ClientIp} the end user's address in HTTP requests AND in the
 * queued work they dispatch.
 *
 * Request: set on `RouteMatched`, which fires after the global middleware —
 * so `TrustProxies` has already run and `$request->ip()` is the real client,
 * not the load balancer.
 *
 * Queue: the address rides in the job PAYLOAD, written while the request is
 * still being handled (`Queue::createPayloadUsing`) and restored by the worker
 * (`JobProcessing`). This is what makes a queued notification that calls
 * `verifications()->start()` from `toNexusMessaging()` forward the address of
 * the user who asked for the code, on whatever worker or server runs it. Jobs
 * the worker dispatches inherit it, so chains keep it too. A job dispatched
 * with no request behind it (a command, the scheduler) carries none.
 */
final class ClientIpPropagation
{
    private const KEY = 'nexusClientIp';

    /** @var list<array{bool, ?string}> what each running job replaced — the sync driver nests jobs */
    private array $stack = [];

    private bool $warned = false;

    /**
     * @param \Closure(string): void            $warn
     * @param (\Closure(callable): void)|null    $payloadUsing defaults to `Queue::createPayloadUsing`
     */
    public function __construct(private readonly \Closure $warn, private readonly ?\Closure $payloadUsing = null)
    {
    }

    /** @param Dispatcher $events */
    public function register(object $events): void
    {
        $events->listen('Illuminate\Routing\Events\RouteMatched', function ($event): void {
            $request = $event->request;
            $ip = $request->ip();
            ClientIp::set($ip);
            $this->checkProxies($request, $ip);
        });

        // Octane keeps the process alive: forget the previous request's user
        // before anything in the next one can send.
        $events->listen('Laravel\Octane\Events\RequestReceived', static fn () => ClientIp::clear());

        ($this->payloadUsing ?? static fn (callable $c) => Queue::createPayloadUsing($c))(static function (): array {
            // Not written at all when forwarding is off: no end-user IPs in the queue.
            $ip = ClientIp::forwarded();

            return $ip === null ? [] : [self::KEY => $ip];
        });

        $events->listen('Illuminate\Queue\Events\JobProcessing', function ($event): void {
            $this->stack[] = ClientIp::snapshot();
            $payload = (array) $event->job->payload();
            ClientIp::set(isset($payload[self::KEY]) ? (string) $payload[self::KEY] : null);
        });
        $restore = function (): void {
            $previous = array_pop($this->stack);
            $previous === null ? ClientIp::clear() : ClientIp::restore($previous);
        };
        $events->listen('Illuminate\Queue\Events\JobProcessed', $restore);
        $events->listen('Illuminate\Queue\Events\JobExceptionOccurred', $restore);
    }

    /**
     * Proxy headers arrived but `ip()` still returned the socket peer: the app
     * sits behind a proxy it does not trust, so every user shares the proxy's
     * address. Said once per process; the address is still forwarded (a
     * client can send that header itself, so ignoring the address here would
     * let anyone skip the limit).
     */
    private function checkProxies(object $request, ?string $ip): void
    {
        if ($this->warned || $ip === null || !method_exists($request, 'server') || !method_exists($request, 'header')) {
            return;
        }
        if ($request->header('X-Forwarded-For') !== null && $ip === $request->server('REMOTE_ADDR')) {
            $this->warned = true;
            ($this->warn)(
                "Nexus: this request came through a proxy (X-Forwarded-For) but Laravel resolved the client IP to the proxy ({$ip}). "
                . 'Configure trusted proxies (TrustProxies / $middleware->trustProxies()) so Messaging\'s per-IP rate limit sees your real users.'
            );
        }
    }
}

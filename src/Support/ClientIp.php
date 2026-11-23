<?php

declare(strict_types=1);

namespace Inverge\Nexus\Support;

/**
 * The END USER's IP address for the request being handled, so Messaging can
 * apply the project's per-IP rate limit without every caller passing it.
 *
 * The messaging API is server-to-server: the address Nexus sees is your
 * server's, the same for every request. So the SDK forwards your end user's
 * address as `X-Client-IP` — on `verifications()->start()` and on
 * `send(messageClass: 'otp')`. Other sends never carry it automatically: a
 * message triggered by a webhook would otherwise be counted against the
 * webhook sender's address.
 *
 * Where the address comes from:
 *  - Laravel: `$request->ip()` once the route matches (so TrustProxies has
 *    run), carried into queued jobs and notifications in the job payload.
 *  - Symfony: `$request->getClientIp()` on the main request, carried across
 *    Messenger in a {@see \Inverge\Nexus\Symfony\ClientIpStamp}.
 *  - Plain PHP: `$_SERVER['REMOTE_ADDR']` (the socket peer — never a header a
 *    client can write).
 *
 * Private, loopback and reserved addresses are never forwarded: one of those
 * is your proxy or load balancer, not a user, and counting every user against
 * it would rate-limit all of them together. Configure your framework's trusted
 * proxies so it resolves the real address.
 */
final class ClientIp
{
    private static bool $enabled = true;

    /** Whether {@see set()} has run for the current request or job. */
    private static bool $known = false;

    private static ?string $ip = null;

    /** Turn automatic forwarding off (Laravel: `NEXUS_FORWARD_CLIENT_IP=false`). */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Record the end user's address for what is being handled now. `null`
     * means "known to have none" (a job dispatched from a command), which stops
     * the `REMOTE_ADDR` fallback.
     */
    public static function set(?string $ip): void
    {
        self::$known = true;
        self::$ip = self::normalize($ip);
    }

    /** Forget it — back to the `REMOTE_ADDR` fallback. */
    public static function clear(): void
    {
        self::$known = false;
        self::$ip = null;
    }

    /**
     * The current state, for {@see restore()} — a job run inline by the sync
     * queue driver must hand the request its address back afterwards.
     *
     * @return array{bool, ?string}
     */
    public static function snapshot(): array
    {
        return [self::$known, self::$ip];
    }

    /** @param array{bool, ?string} $state from {@see snapshot()} */
    public static function restore(array $state): void
    {
        [self::$known, self::$ip] = $state;
    }

    /**
     * Run `$fn` with a different address — or none: `ClientIp::run(null, …)`
     * in a webhook handler that sends codes on someone else's behalf.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public static function run(?string $ip, callable $fn): mixed
    {
        $previous = self::snapshot();
        self::set($ip);
        try {
            return $fn();
        } finally {
            self::restore($previous);
        }
    }

    /**
     * The address to forward, or null. Ignores {@see disable()} — use
     * {@see forwarded()} for what actually goes on the wire.
     */
    public static function current(): ?string
    {
        if (self::$known) {
            return self::$ip;
        }
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            return null;
        }

        return self::normalize(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null);
    }

    /** What the SDK sends as `X-Client-IP`: {@see current()} unless forwarding is off. */
    public static function forwarded(): ?string
    {
        return self::$enabled ? self::current() : null;
    }

    /** The headers to add to a request, given the address the caller passed (if any). */
    public static function headers(?string $explicit): array
    {
        if ($explicit !== null) {
            return []; // sent in the body; the server prefers it over the header
        }
        $ip = self::forwarded();

        return $ip === null ? [] : ['X-Client-IP' => $ip];
    }

    /** A public IPv4/IPv6 address, or null. */
    public static function normalize(?string $ip): ?string
    {
        $ip = $ip === null ? '' : trim($ip);
        if ($ip === '') {
            return null;
        }
        $public = filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);

        return $public === false ? null : $ip;
    }
}

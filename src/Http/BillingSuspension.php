<?php

declare(strict_types=1);

namespace Inverge\Nexus\Http;

use Inverge\Nexus\Config;
use Inverge\Nexus\Contracts\SuspensionStore;
use Inverge\Nexus\Exception\BillingSuspendedException;

/**
 * The billing-suspension circuit breaker.
 *
 * When the API answers 402 `billing_suspended` (an overdue invoice), the SDK
 * stops calling it — from this process AND every process sharing the
 * {@see SuspensionStore} — for the pause the API asks for (`retryAfterSeconds`,
 * default 5 minutes). Calls made meanwhile fail locally, without a request.
 * When the pause ends, ONE request goes out as a probe (it holds a short lease
 * so the other processes keep waiting): a 402 starts a new pause, anything else
 * means access is back.
 *
 * Without this, an app keeps calling a suspended API at full rate — and an app
 * that reports its own failures to Nexus Errors turns every refused call into
 * another refused call.
 *
 * Messaging is exempt: it is paid from its own prepaid wallet, and the API
 * never suspends it for an unpaid invoice.
 */
final class BillingSuspension
{
    /** Pause when the API doesn't say how long. */
    public const DEFAULT_SECONDS = 300;

    private const MIN_SECONDS = 30;
    private const MAX_SECONDS = 3600;

    /** How long the probing process keeps the others waiting. */
    private const PROBE_LEASE = 30;

    /** How often a process re-reads the shared store while not paused. */
    private const RECHECK_SECONDS = 5;

    private static ?SuspensionStore $store = null;

    /** @var array<string, array{until:?int, checked:int}> */
    private static array $memo = [];

    /** Share the pause through `$store` (null = the default file store). */
    public static function useStore(?SuspensionStore $store): void
    {
        self::$store = $store;
        self::$memo = [];
    }

    /** Whether `$path` is billed from a prepaid wallet and so never suspended. */
    public static function exempt(string $path): bool
    {
        return $path === '/partner/messaging' || str_starts_with($path, '/partner/messaging/')
            || str_starts_with($path, '/partner/messaging?');
    }

    /** Seconds the pause still has to run for this key (0 = not paused). */
    public static function remaining(Config $config): int
    {
        $until = self::until(self::key($config));

        return $until === null ? 0 : max(0, $until - time());
    }

    /**
     * Whether `$path` is paused right now — read-only: it never takes the probe
     * lease, so a caller can ask before handing the request to a dispatcher
     * (which then runs {@see check()} itself).
     */
    public static function refusal(Config $config, string $path): ?BillingSuspendedException
    {
        if (self::exempt($path)) {
            return null;
        }
        $remaining = self::remaining($config);

        return $remaining > 0 ? self::localException($remaining) : null;
    }

    /**
     * Before a request: the exception to throw instead of sending it, or null
     * to send. When a pause has just ended, the caller becomes the probe.
     */
    public static function check(Config $config, string $path): ?BillingSuspendedException
    {
        if (self::exempt($path)) {
            return null;
        }
        $key = self::key($config);
        $until = self::until($key);
        if ($until === null) {
            return null;
        }
        $now = time();
        if ($until > $now) {
            return self::localException($until - $now);
        }
        // The pause is over: this request is the probe. Hold the others back
        // while it is in flight, so a fleet of workers doesn't wake up at once.
        self::write($key, $now + self::PROBE_LEASE);

        return null;
    }

    /**
     * After a response: a 402 `billing_suspended` starts (or extends) the
     * pause, any other HTTP answer proves access works and lifts it. Returns the
     * pause length when one started.
     *
     * Only that error code counts — Messaging answers 402 `insufficient_balance`
     * for an empty prepaid wallet, which is not a suspension.
     *
     * @param array<mixed>|null $details the 402's `error.details`
     */
    public static function record(Config $config, string $path, int $status, ?string $code = null, ?array $details = null): ?int
    {
        $key = self::key($config);
        if (self::isSuspension($status, $code)) {
            $seconds = self::seconds($details['retryAfterSeconds'] ?? null);
            self::write($key, time() + $seconds);

            return $seconds;
        }
        if (!self::exempt($path) && (self::$memo[$key]['until'] ?? null) !== null) {
            self::$memo[$key] = ['until' => null, 'checked' => time()];
            self::store()->forget($key);
        }

        return null;
    }

    /** Whether an API answer is the billing-suspension refusal. */
    public static function isSuspension(int $status, ?string $code): bool
    {
        return $status === 402 && $code === 'billing_suspended';
    }

    /** Forget every pause (tests, or after settling an invoice by hand). */
    public static function reset(?Config $config = null): void
    {
        if ($config === null) {
            self::$memo = [];

            return;
        }
        $key = self::key($config);
        unset(self::$memo[$key]);
        self::store()->forget($key);
    }

    /**
     * An error report whose subject is the API's own billing refusal. Reporting
     * that to the API that refused it can only be refused again.
     *
     * @param array<string, mixed>|null $json
     */
    public static function isSuspensionReport(string $path, ?array $json): bool
    {
        if ($path !== '/partner/errors' || $json === null) {
            return false;
        }
        $type = (string) ($json['type'] ?? '');

        return str_starts_with($type, 'Inverge\\Nexus\\Exception\\')
            && str_starts_with((string) ($json['message'] ?? ''), 'API access is suspended');
    }

    public static function localException(int $remaining): BillingSuspendedException
    {
        return new BillingSuspendedException(
            'API access is suspended for an overdue invoice. Nexus calls are paused for '
            . max(1, $remaining) . ' more seconds; settle the outstanding balance to restore access.',
            max(1, $remaining),
            true,
        );
    }

    private static function until(string $key): ?int
    {
        $now = time();
        $memo = self::$memo[$key] ?? null;
        // A known pause is trusted until it ends; "not paused" is re-read from
        // the shared store every few seconds so another process's 402 reaches us.
        if ($memo !== null && (($memo['until'] ?? 0) > $now || $now - $memo['checked'] < self::RECHECK_SECONDS)) {
            return $memo['until'];
        }
        $until = self::store()->get($key);
        self::$memo[$key] = ['until' => $until, 'checked' => $now];

        return $until;
    }

    private static function write(string $key, int $until): void
    {
        self::$memo[$key] = ['until' => $until, 'checked' => time()];
        self::store()->put($key, $until);
    }

    private static function seconds(mixed $raw): int
    {
        $s = is_numeric($raw) ? (int) $raw : self::DEFAULT_SECONDS;

        return min(self::MAX_SECONDS, max(self::MIN_SECONDS, $s));
    }

    private static function key(Config $config): string
    {
        return substr(hash('sha256', $config->httpBase() . '|' . $config->apiKey), 0, 32);
    }

    private static function store(): SuspensionStore
    {
        return self::$store ??= new FileSuspensionStore();
    }
}

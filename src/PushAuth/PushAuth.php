<?php

declare(strict_types=1);

namespace Inverge\Nexus\PushAuth;

use Inverge\Nexus\Http\SyncDispatcher;

/**
 * Push Authentication — approve sign-ins and transactions on the user's phone
 * (match the number, tap, code display, or silently).
 *
 * ```php
 * $pa = $nexus->pushAuth();   // the client's key must be granted server access
 * $token = $pa->createEnrollment($user->id)['token'];        // → the app enrolls with it
 *
 * $c = $pa->createChallenge($user->id, 'Sign in to Acme', method: 'number_match',
 *     context: ['device' => 'Chrome on macOS', 'ip' => $request->ip()]);
 * // show $c['requesterView']['number'] on the sign-in screen
 * $done = $pa->waitForChallenge($c['id']);
 * if ($done['status'] === 'approved') {
 *     $receipt = $pa->verifyReceipt($done['receipt']);       // single-use
 * }
 * ```
 *
 * The client's API key must be GRANTED Push Authentication server access
 * (console → API keys → the key → Push Authentication → Server access; off by default)
 * — a server-only key, never one shipped in an app. Without the grant calls
 * throw with `server_access_required`. Failures throw {@see \Inverge\Nexus\Exception\ApiException}
 * (`errorCode`: `no_factors`, `identity_locked`, `too_many_pending`, …). When
 * the service is switched off (`NEXUS_PUSH_AUTH_ENABLED=false`) calls return null.
 */
final class PushAuth
{
    private const BASE = '/partner/push-auth';

    public function __construct(
        private readonly SyncDispatcher $dispatcher,
        private readonly \Closure $enabled,
        private readonly float $timeout = 10.0,
    ) {
    }

    /** A single-use enrollment token (10 min) for the app: `Nexus.instance.pushAuth.enroll(token)`. */
    public function createEnrollment(string $identity, ?string $friendlyName = null, ?array $metadata = null, ?int $ttlSeconds = null): ?array
    {
        return $this->call('POST', '/enrollments', self::compact(['identity' => $identity, 'friendlyName' => $friendlyName, 'metadata' => $metadata, 'ttlSeconds' => $ttlSeconds]));
    }

    /** @return array<int, array<string, mixed>>|null */
    public function listFactors(?string $identity = null, ?string $status = null, ?int $limit = null): ?array
    {
        $q = http_build_query(self::compact(['identity' => $identity, 'status' => $status, 'limit' => $limit]));
        return $this->call('GET', '/factors' . ($q !== '' ? '?' . $q : ''));
    }

    public function getFactor(string $factorId): ?array
    {
        return $this->call('GET', '/factors/' . rawurlencode($factorId));
    }

    public function revokeFactor(string $factorId): ?array
    {
        return $this->call('DELETE', '/factors/' . rawurlencode($factorId));
    }

    public function lockFactor(string $factorId): ?array
    {
        return $this->call('POST', '/factors/' . rawurlencode($factorId) . '/lock', []);
    }

    public function unlockFactor(string $factorId): ?array
    {
        return $this->call('POST', '/factors/' . rawurlencode($factorId) . '/unlock', []);
    }

    public function getIdentity(string $identity): ?array
    {
        return $this->call('GET', '/identities/' . rawurlencode($identity));
    }

    public function unlockIdentity(string $identity): ?array
    {
        return $this->call('POST', '/identities/' . rawurlencode($identity) . '/unlock', []);
    }

    /**
     * Start an approval. `requesterView` holds what your screen shows (e.g. the number).
     *
     * @param list<array{label:string,value:string}>|null $fields
     * @param array<string,string>|null                   $context       ip, location, device, app — shown on the phone
     * @param list<string>|null                           $methods       an escalation chain, e.g. ['silent', 'number_match']
     * @param array<string, array<string,mixed>>|null     $methodOptions e.g. ['number_match' => ['entry' => 'type']]
     */
    public function createChallenge(
        string $identity,
        string $message,
        ?string $method = null,
        ?array $fields = null,
        ?array $context = null,
        ?array $methods = null,
        ?array $methodOptions = null,
        ?string $factorId = null,
        ?array $hiddenDetails = null,
        ?int $expiresInSeconds = null,
        ?string $fallbackTo = null,
        ?string $ref = null,
        ?string $idempotencyKey = null,
    ): ?array {
        $details = self::compact(['message' => $message, 'fields' => $fields, 'context' => $context]);
        return $this->call('POST', '/challenges', self::compact([
            'identity' => $identity,
            'details' => $details,
            'method' => $method,
            'methods' => $methods,
            'methodOptions' => $methodOptions,
            'factorId' => $factorId,
            'hiddenDetails' => $hiddenDetails,
            'expiresInSeconds' => $expiresInSeconds,
            'fallback' => $fallbackTo !== null ? ['to' => $fallbackTo] : null,
            'ref' => $ref,
            'idempotencyKey' => $idempotencyKey,
        ]));
    }

    /**
     * Current state. `$wait` (seconds) long-polls: the call returns as soon as
     * anything changes. Capped below the client timeout so it never times out.
     */
    public function getChallenge(string $challengeId, int $wait = 0): ?array
    {
        $wait = max(0, min($wait, 25, (int) floor($this->timeout) - 2));
        return $this->call('GET', '/challenges/' . rawurlencode($challengeId) . ($wait > 0 ? '?wait=' . $wait : ''));
    }

    /** Long-poll until decided — or until `$untilStage` (e.g. 'awaiting_code'). */
    public function waitForChallenge(string $challengeId, int $timeoutSeconds = 3600, ?string $untilStage = null): ?array
    {
        $deadline = time() + $timeoutSeconds;
        $c = $this->getChallenge($challengeId);
        while ($c !== null && ($c['status'] ?? null) === 'pending' && ($untilStage === null || ($c['stage'] ?? null) !== $untilStage)) {
            $left = $deadline - time();
            if ($left <= 0) {
                break;
            }
            $c = $this->getChallenge($challengeId, max(1, min(25, $left)));
        }
        return $c;
    }

    /** The code the user typed (code display, or the SMS/Telegram fallback). */
    public function complete(string $challengeId, string $code): ?array
    {
        return $this->call('POST', '/challenges/' . rawurlencode($challengeId) . '/complete', ['code' => $code]);
    }

    public function cancel(string $challengeId): ?array
    {
        return $this->call('POST', '/challenges/' . rawurlencode($challengeId) . '/cancel', []);
    }

    /** Verify an approval receipt; `$consume` (default) makes it single-use. */
    public function verifyReceipt(string $token, bool $consume = true): ?array
    {
        return $this->call('POST', '/tokens/verify', ['token' => $token, 'consume' => $consume]);
    }

    /**
     * Verify a webhook: HMAC-SHA256 over `timestamp.nonce.body`. Pass the RAW
     * body and the `x-nexus-signature` / `x-nexus-timestamp` / `x-nexus-nonce`
     * headers. Dedupe on the body's `id` — deliveries are at-least-once.
     */
    public static function verifyWebhook(string $body, ?string $signature, string|int|null $timestamp, ?string $nonce, string $secret, int $tolerance = 300): bool
    {
        if ($signature === null || $nonce === null || $timestamp === null || !is_numeric((string) $timestamp)) {
            return false;
        }
        $ts = (int) $timestamp;
        if (abs(time() - $ts) > $tolerance) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $ts . '.' . $nonce . '.' . $body, $secret), $signature);
    }

    private function call(string $method, string $path, ?array $json = null): ?array
    {
        if (!($this->enabled)()) {
            return null;
        }
        return $this->dispatcher->dispatch($method, self::BASE . $path, $json);
    }

    /** @param array<string, mixed> $a */
    private static function compact(array $a): array
    {
        return array_filter($a, static fn ($v) => $v !== null);
    }
}

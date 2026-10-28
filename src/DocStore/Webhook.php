<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

use Inverge\Nexus\Exception\NexusException;

/**
 * Verify DocStore trigger webhooks. Signature = HMAC-SHA256 (hex) of
 * "{timestamp}.{nonce}.{rawBody}" with the trigger's `whsec_…` secret, in
 * `x-nexus-signature` + `x-nexus-timestamp` + `x-nexus-nonce`.
 *
 *     $payload = Webhook::verify(file_get_contents('php://input'), getallheaders(), $secret);
 *
 * Deliveries are at-least-once: dedupe on `$payload['deliveryId']` (or `seq`).
 */
final class Webhook
{
    /**
     * @param array<string, string|list<string>> $headers
     * @param (callable(string): bool)|null      $seenNonce return true if this nonce was already used (replay)
     * @return array<string, mixed>
     *
     * @throws NexusException when the signature, timestamp or nonce fails
     */
    public static function verify(string $rawBody, array $headers, string $secret, int $tolerance = 300, ?callable $seenNonce = null, ?int $now = null): array
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }
        $sig = $h['x-nexus-signature'] ?? '';
        $ts = $h['x-nexus-timestamp'] ?? '';
        $nonce = $h['x-nexus-nonce'] ?? '';
        if ($sig === '' || $ts === '' || $nonce === '') {
            throw new NexusException('DocStore webhook: missing x-nexus-signature / x-nexus-timestamp / x-nexus-nonce');
        }
        if (!ctype_digit($ts) || abs(($now ?? time()) - (int) $ts) > $tolerance) {
            throw new NexusException("DocStore webhook: timestamp outside the {$tolerance}s tolerance");
        }
        $expected = hash_hmac('sha256', "{$ts}.{$nonce}.{$rawBody}", $secret);
        if (!hash_equals($expected, $sig)) {
            throw new NexusException('DocStore webhook: signature mismatch');
        }
        if ($seenNonce !== null && $seenNonce($nonce)) {
            throw new NexusException('DocStore webhook: nonce already used (replay)');
        }
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        return is_array($payload) ? $payload : [];
    }
}

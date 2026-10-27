<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * Shield — verify that a request really comes from your app on a real device.
 *
 * Your app (Nexus client SDK with Shield on) sends `X-Nexus-Shield` (a
 * device-bound token) and `DPoP` (a single-use proof for that exact method +
 * URL) with each request to YOUR backend. Pass them here and act on
 * `decision`:
 *
 *     $v = $nexus->shield()->verifyRequest($headers, 'POST', $url, $rawBody, 'checkout');
 *     if (($v['decision'] ?? 'deny') !== 'allow') { http_response_code(401); exit; }
 *
 * Replay protection, blocked devices and the policy you configured for the
 * resource are applied server-side. Use a SERVER-side API key.
 */
final class Shield extends AbstractResource
{
    /**
     * @return array{valid:bool,decision:string,mode:string,reason:?string,resource:string,policy:?string,token:?array<string,mixed>}
     */
    public function verify(?string $token, ?string $proof, string $method, string $url, ?string $resource = null, ?string $bodyHash = null): array
    {
        $res = $resource ?? '*';
        if ($token === null || $token === '') {
            return ['valid' => false, 'decision' => 'deny', 'mode' => 'none', 'reason' => 'token_missing', 'resource' => $res, 'policy' => null, 'token' => null];
        }
        if (stripos($token, 'DPoP ') === 0) {
            $token = substr($token, 5);
        }
        // Never retried: verify burns the proof's jti.
        $out = $this->client->request('POST', '/partner/shield/verify', $this->compact([
            'token' => $token,
            'proof' => $proof,
            'method' => strtoupper($method),
            'url' => $url,
            'resource' => $resource,
            'bodyHash' => $bodyHash,
        ]));
        if (!is_array($out)) {
            // Switched off / no answer: let the caller decide fail-open vs fail-closed.
            return ['valid' => false, 'decision' => 'deny', 'mode' => 'none', 'reason' => 'unavailable', 'resource' => $res, 'policy' => null, 'token' => null];
        }
        /** @var array{valid:bool,decision:string,mode:string,reason:?string,resource:string,policy:?string,token:?array<string,mixed>} $out */
        return $out;
    }

    /**
     * Verify straight from request headers (any case; PSR-7 style arrays work).
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return array{valid:bool,decision:string,mode:string,reason:?string,resource:string,policy:?string,token:?array<string,mixed>}
     */
    public function verifyRequest(array $headers, string $method, string $url, ?string $rawBody = null, ?string $resource = null): array
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = is_array($v) ? ($v[0] ?? null) : $v;
        }

        return $this->verify($h['x-nexus-shield'] ?? null, $h['dpop'] ?? null, $method, $url, $resource, self::bodyHash($rawBody));
    }

    /** base64url SHA-256 of a raw body — what the client's `bh` claim holds. */
    public static function bodyHash(?string $rawBody): ?string
    {
        if ($rawBody === null) {
            return null;
        }

        return rtrim(strtr(base64_encode(hash('sha256', $rawBody, true)), '+/', '-_'), '=');
    }
}

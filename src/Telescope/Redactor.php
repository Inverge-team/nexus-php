<?php

declare(strict_types=1);

namespace Inverge\Nexus\Telescope;

/**
 * Removes secrets before anything leaves the process. Keys are matched
 * case-insensitively; any key containing "password" or "secret" is always
 * redacted. The Nexus server redacts again with the environment's own list.
 */
final class Redactor
{
    public const REDACTED = '********';

    public const DEFAULT_KEYS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-csrf-token', 'x-xsrf-token',
        'php-auth-pw', 'token', 'access_token', 'refresh_token', 'id_token', 'api_key', 'apikey', 'client_secret',
        'private_key', 'credit_card', 'card_number', 'cvv', 'cvc', 'ssn', '_token',
    ];

    /** @var array<string, true> */
    private array $keys = [];

    /** @param list<string> $extra */
    public function __construct(array $extra = [])
    {
        foreach (array_merge(self::DEFAULT_KEYS, $extra) as $k) {
            $this->keys[strtolower((string) $k)] = true;
        }
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function redact(array $data, int $depth = 0): array
    {
        if ($depth > 10) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (\is_string($k)) {
                $lk = strtolower($k);
                if (isset($this->keys[$lk]) || str_contains($lk, 'password') || str_contains($lk, 'secret')) {
                    $data[$k] = $v === null || $v === '' ? $v : self::REDACTED;
                    continue;
                }
            }
            if (\is_array($v)) {
                $data[$k] = $this->redact($v, $depth + 1);
            }
        }

        return $data;
    }

    /** `200` (exact), `2xx` (class) or `301-304` (inclusive range) against a 3-digit status. */
    public static function statusMatch(string $pattern, string $status): bool
    {
        if (!preg_match('/^\d{3}$/', $status)) {
            return false;
        }
        $p = strtolower(trim($pattern));
        if (preg_match('/^\dxx$/', $p)) {
            return $p[0] === $status[0];
        }
        if (preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $p, $m)) {
            return (int) $status >= (int) $m[1] && (int) $status <= (int) $m[2];
        }

        return $p === $status;
    }

    public static function globMatch(string $glob, string $value): bool
    {
        $re = '#^' . implode('.*', array_map(static fn (string $p) => preg_quote($p, '#'), explode('*', $glob))) . '$#i';

        return (bool) preg_match($re, $value);
    }
}

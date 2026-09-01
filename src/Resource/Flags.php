<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * Feature flags — evaluate a project's active flags for a specific person.
 * Response shape: `{ flags: {key: bool|variantString}, payloads: {key: mixed} }`.
 */
final class Flags extends AbstractResource
{
    /**
     * Evaluate every active flag for `$distinctId`.
     *
     * @param array<string, mixed> $properties targeting properties
     *
     * @return array{flags:array<string,bool|string>,payloads:array<string,mixed>}
     */
    public function evaluate(string $distinctId, array $properties = []): array
    {
        $result = $this->client->request('POST', '/partner/flags/evaluate', $this->compact([
            'distinctId' => $distinctId,
            'properties' => $properties !== [] ? $properties : null,
        ])) ?? [];

        return [
            'flags' => $result['flags'] ?? [],
            'payloads' => $result['payloads'] ?? [],
        ];
    }

    /**
     * Whether a flag is on for the person. Boolean flags → their value;
     * multivariate flags → true when a variant resolved.
     *
     * @param array<string, mixed> $properties
     */
    public function isEnabled(string $key, string $distinctId, array $properties = []): bool
    {
        $value = $this->evaluate($distinctId, $properties)['flags'][$key] ?? false;

        return $value === true || (is_string($value) && $value !== '');
    }

    /**
     * The resolved variant key for a multivariate flag (null for boolean/off).
     *
     * @param array<string, mixed> $properties
     */
    public function variant(string $key, string $distinctId, array $properties = []): ?string
    {
        $value = $this->evaluate($distinctId, $properties)['flags'][$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The flag's JSON payload for the person (null when disabled/absent).
     *
     * @param array<string, mixed> $properties
     */
    public function payload(string $key, string $distinctId, array $properties = []): mixed
    {
        return $this->evaluate($distinctId, $properties)['payloads'][$key] ?? null;
    }
}

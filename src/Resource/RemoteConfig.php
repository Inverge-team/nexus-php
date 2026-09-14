<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * Remote Config — fetch the active, published configuration template resolved
 * for a given app-instance context. Conditions (platform, version, country,
 * percentile, user properties, …) are evaluated server-side; you get back the
 * final value for every parameter. Useful server-side for headless config,
 * feature gating, and rendering server-driven UIs without a redeploy.
 */
final class RemoteConfig extends AbstractResource
{
    /**
     * Fetch and resolve the active template for this instance's context.
     *
     * Pass the previous response's `etag` to skip the payload when nothing has
     * changed (`notModified` will be true).
     *
     * @param array{appInstanceId?:string,appVersion?:string,appBuild?:int,platform?:string,osVersion?:string,country?:string,language?:string,firstOpenTime?:string,userProperties?:array<string,mixed>} $context
     *
     * @return array{version:int,etag:string,notModified:bool,throttled?:bool,parameters:array<string,array{value:mixed,valueType:string,source:?string}>}
     */
    public function fetch(array $context = [], ?string $etag = null): array
    {
        $headers = $etag !== null ? ['If-None-Match' => $etag] : [];

        $result = $this->client->request('POST', '/partner/remote-config/fetch', $this->compact([
            'appInstanceId' => $context['appInstanceId'] ?? null,
            'appVersion' => $context['appVersion'] ?? null,
            'appBuild' => $context['appBuild'] ?? null,
            'platform' => $context['platform'] ?? null,
            'osVersion' => $context['osVersion'] ?? null,
            'country' => $context['country'] ?? null,
            'language' => $context['language'] ?? null,
            'firstOpenTime' => $context['firstOpenTime'] ?? null,
            'userProperties' => ($context['userProperties'] ?? null) ?: null,
        ]), $headers);

        return $result ?? ['version' => 0, 'etag' => 'empty', 'notModified' => false, 'parameters' => []];
    }

    /**
     * Convenience: the resolved config as a flat `key => value` map.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function all(array $context = []): array
    {
        $result = $this->fetch($context);
        $out = [];
        foreach (($result['parameters'] ?? []) as $key => $entry) {
            $out[$key] = $entry['value'] ?? null;
        }

        return $out;
    }

    /**
     * Convenience: a single parameter's resolved value, or [$default].
     *
     * @param array<string, mixed> $context
     */
    public function get(string $key, mixed $default = null, array $context = []): mixed
    {
        $params = $this->fetch($context)['parameters'] ?? [];

        return \array_key_exists($key, $params) ? ($params[$key]['value'] ?? $default) : $default;
    }

    // ── Write ────────────────────────────────────────────────────────────────
    // These edit the DRAFT template; changes go LIVE only after publish().
    // Scoped to this API key's environment. Requires a key with write access.

    /**
     * Create or update a parameter in the draft template.
     *
     * @param 'STRING'|'NUMBER'|'BOOLEAN'|'JSON' $valueType
     * @param array<string, mixed>|null          $conditionalValues conditionName => value
     *
     * @return array<string, mixed> the stored parameter
     */
    public function setParameter(
        string $key,
        string $valueType,
        mixed $defaultValue = null,
        ?array $conditionalValues = null,
        ?string $description = null,
    ): array {
        return $this->client->request('PUT', '/partner/remote-config/parameters', $this->compact([
            'key' => $key,
            'valueType' => $valueType,
            'defaultValue' => $defaultValue,
            'conditionalValues' => $conditionalValues ?: null,
            'description' => $description,
        ])) ?? [];
    }

    /** Remove a parameter from the draft template. */
    public function deleteParameter(string $key): void
    {
        $this->client->request('DELETE', '/partner/remote-config/parameters/' . rawurlencode($key));
    }

    /**
     * Publish the current draft — makes all pending parameter changes live for
     * every fetch() from now on.
     *
     * @return array{versionNumber:int,etag:string,createdAt:string}
     */
    public function publish(?string $description = null): array
    {
        return $this->client->request('POST', '/partner/remote-config/publish', $this->compact([
            'description' => $description,
        ])) ?? [];
    }

    /**
     * Convenience: set one parameter and publish it in a single call. The value
     * type is inferred from $value unless given explicitly.
     *
     * @param 'STRING'|'NUMBER'|'BOOLEAN'|'JSON'|null $valueType
     *
     * @return array{versionNumber:int,etag:string,createdAt:string} the new version
     */
    public function set(string $key, mixed $value, ?string $valueType = null, ?string $description = null): array
    {
        $this->setParameter($key, $valueType ?? self::inferType($value), $value, null, $description);

        return $this->publish($description);
    }

    /** @return 'STRING'|'NUMBER'|'BOOLEAN'|'JSON' */
    private static function inferType(mixed $value): string
    {
        return match (true) {
            \is_bool($value) => 'BOOLEAN',
            \is_int($value), \is_float($value) => 'NUMBER',
            \is_array($value) => 'JSON',
            default => 'STRING',
        };
    }
}

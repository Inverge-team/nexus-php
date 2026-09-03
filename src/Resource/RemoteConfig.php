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
}

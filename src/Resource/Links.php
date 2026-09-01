<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * Deep links & attribution — record a conversion against a link/click.
 */
final class Links extends AbstractResource
{
    /**
     * Record an attribution event.
     *
     * @param 'install'|'open'|'reengagement'|'uninstall'|'in_app'          $type
     * @param array{name?:string,clickId?:string,distinctId?:string,deviceId?:string,sessionKey?:string,platform?:string,osType?:string,country?:string,revenue?:float|int,properties?:array<string,mixed>} $options
     *
     * @return array<mixed>
     */
    public function attribute(string $type, array $options = []): array
    {
        return $this->client->request('POST', '/partner/links/attribute', $this->compact([
            'type' => $type,
            'name' => $options['name'] ?? null,
            'clickId' => $options['clickId'] ?? null,
            'distinctId' => $options['distinctId'] ?? null,
            'deviceId' => $options['deviceId'] ?? null,
            'sessionKey' => $options['sessionKey'] ?? null,
            'platform' => $options['platform'] ?? null,
            'osType' => $options['osType'] ?? null,
            'country' => $options['country'] ?? null,
            'revenue' => $options['revenue'] ?? null,
            'properties' => $options['properties'] ?? null,
        ])) ?? [];
    }
}

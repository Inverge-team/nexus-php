<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * The sessions spine — identify end-users and start/refresh journey sessions
 * that other products (events, errors, logs) correlate into.
 */
final class Sessions extends AbstractResource
{
    /**
     * Identify (upsert) an end-user.
     *
     * @param array{email?:string,name?:string,phone?:string,traits?:array<string,mixed>} $options
     *
     * @return array<mixed>
     */
    public function identify(string $distinctId, array $options = []): array
    {
        return $this->client->request('POST', '/partner/sessions/identify', $this->compact([
            'distinctId' => $distinctId,
            'email' => $options['email'] ?? null,
            'name' => $options['name'] ?? null,
            'phone' => $options['phone'] ?? null,
            'traits' => $options['traits'] ?? null,
        ])) ?? [];
    }

    /**
     * Start/refresh the active session and get its `sessionId`.
     *
     * @param array{distinctId?:string,email?:string,name?:string,traits?:array<string,mixed>,deviceKey?:string,sessionKey?:string,browser?:string,appVersion?:string,country?:string,entryUrl?:string,osType?:string,osVersion?:string} $options
     *
     * @return array<mixed>
     */
    public function track(array $options = []): array
    {
        return $this->client->request('POST', '/partner/sessions/track', $this->compact([
            'distinctId' => $options['distinctId'] ?? null,
            'email' => $options['email'] ?? null,
            'name' => $options['name'] ?? null,
            'traits' => $options['traits'] ?? null,
            'deviceKey' => $options['deviceKey'] ?? null,
            'sessionKey' => $options['sessionKey'] ?? null,
            'browser' => $options['browser'] ?? null,
            'appVersion' => $options['appVersion'] ?? null,
            'country' => $options['country'] ?? null,
            'entryUrl' => $options['entryUrl'] ?? null,
            'osType' => $options['osType'] ?? null,
            'osVersion' => $options['osVersion'] ?? null,
        ])) ?? [];
    }
}

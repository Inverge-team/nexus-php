<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * Product analytics — capture events, optionally correlated to a person/session.
 */
final class Events extends AbstractResource
{
    /**
     * Capture a single event.
     *
     * @param array<string, mixed> $properties
     * @param array{distinctId?:string,sessionKey?:string,deviceKey?:string,release?:string,osType?:string,osVersion?:string,browser?:string,appVersion?:string,timestamp?:string} $context
     *
     * @return int number of events written
     */
    public function capture(string $name, array $properties = [], array $context = []): int
    {
        $event = $this->compact([
            'name' => $name,
            'properties' => $properties !== [] ? $properties : null,
            'timestamp' => $context['timestamp'] ?? null,
        ]);

        return $this->batch([$event], $context);
    }

    /**
     * Capture a batch of events sharing one identity/context. Each event:
     * `['name' => string, 'properties' => array, 'timestamp' => string]`.
     *
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed>        $context
     */
    public function batch(array $events, array $context = []): int
    {
        $body = $this->compact([
            'events' => array_values($events),
            'release' => $context['release'] ?? null,
            'distinctId' => $context['distinctId'] ?? null,
            'sessionKey' => $context['sessionKey'] ?? null,
            'deviceKey' => $context['deviceKey'] ?? null,
            'osType' => $context['osType'] ?? null,
            'osVersion' => $context['osVersion'] ?? null,
            'browser' => $context['browser'] ?? null,
            'appVersion' => $context['appVersion'] ?? null,
        ]);

        $result = $this->client->request('POST', '/partner/events', $body);

        return (int) ($result['written'] ?? count($events));
    }
}

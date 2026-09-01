<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * Realtime (rooms) — server-side emit + room management over the `/partner/rooms`
 * HTTP surface. Emitting broadcasts to every client currently joined to the room.
 */
final class Realtime extends AbstractResource
{
    /**
     * Emit one or more named events to a room.
     *
     * @param string|list<string> $events
     * @param mixed                $payload JSON-serialisable payload
     *
     * @return array<mixed> the ack: { ok, room, related, events, recipients }
     */
    public function emit(string $room, string|array $events, mixed $payload = null): array
    {
        return $this->client->request('POST', '/partner/rooms/' . rawurlencode($room) . '/emit', [
            'events' => is_array($events) ? array_values($events) : [$events],
            'payload' => $payload,
        ]) ?? [];
    }

    /**
     * Emit to many rooms in one request. Each message: `['room' => string,
     * 'event' => string|list<string>, 'payload' => mixed]`.
     *
     * @param list<array{room?:string,name?:string,event?:mixed,events?:mixed,payload?:mixed}> $messages
     *
     * @return array<mixed> { ok, count, results }
     */
    public function broadcast(array $messages): array
    {
        return $this->client->request('POST', '/partner/rooms/emit', [
            'rooms' => array_values($messages),
        ]) ?? [];
    }

    /**
     * Register (create) a room. Rooms are also auto-created on first join/emit;
     * registering lets you set a type up front.
     *
     * @return array<mixed>
     */
    public function registerRoom(string $name, ?string $type = null): array
    {
        return $this->client->request('POST', '/partner/rooms', $this->compact([
            'name' => $name,
            'type' => $type,
        ])) ?? [];
    }

    /**
     * List the environment's registered rooms.
     *
     * @return array<mixed>
     */
    public function rooms(): array
    {
        $body = $this->client->request('GET', '/partner/rooms');

        return $body['rooms'] ?? [];
    }

    public function deleteRoom(string $id): void
    {
        $this->client->request('DELETE', '/partner/rooms/' . rawurlencode($id));
    }

    public function link(string $id, string $relatedId): void
    {
        $this->client->request('POST', '/partner/rooms/' . rawurlencode($id) . '/link/' . rawurlencode($relatedId));
    }

    public function unlink(string $id, string $relatedId): void
    {
        $this->client->request('POST', '/partner/rooms/' . rawurlencode($id) . '/unlink/' . rawurlencode($relatedId));
    }

    /** @return array<mixed> */
    public function schema(string $id): array
    {
        return $this->client->request('GET', '/partner/rooms/' . rawurlencode($id) . '/schema') ?? [];
    }

    /**
     * @param array<string, mixed> $schema a JSON Schema for the room's payloads
     *
     * @return array<mixed>
     */
    public function setSchema(string $id, array $schema): array
    {
        return $this->client->request('POST', '/partner/rooms/' . rawurlencode($id) . '/schema', [
            'schema' => $schema,
        ]) ?? [];
    }

    /** @return array<mixed> */
    public function enableSchema(string $id, bool $enabled = true): array
    {
        return $this->client->request('POST', '/partner/rooms/' . rawurlencode($id) . '/schema/enable', [
            'enabled' => $enabled,
        ]) ?? [];
    }

    public function clearSchema(string $id): void
    {
        $this->client->request('DELETE', '/partner/rooms/' . rawurlencode($id) . '/schema');
    }

    /**
     * Rooms related (linked) to the given room name.
     *
     * @return array<mixed>
     */
    public function related(string $name): array
    {
        return $this->client->request('GET', '/partner/rooms/' . rawurlencode($name) . '/related') ?? [];
    }
}

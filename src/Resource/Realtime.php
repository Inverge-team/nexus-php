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
            'events' => $this->normalizeEvents($events),
            'payload' => $payload,
        ]) ?? [];
    }

    /**
     * Broadcast to many rooms (each with its own events/payload) in one request.
     * Each message: `['room' => string, 'event' => string|list<string>, 'payload' => mixed]`
     * (`name`/`events` are also accepted).
     *
     * @param list<array{room?:string,name?:string,event?:mixed,events?:mixed,payload?:mixed}> $messages
     *
     * @return array<mixed> { ok, count, results }
     */
    public function broadcast(array $messages): array
    {
        $rooms = [];
        foreach ($messages as $message) {
            $rooms[] = [
                'name' => (string) ($message['room'] ?? $message['name'] ?? ''),
                'events' => $this->normalizeEvents($message['events'] ?? $message['event'] ?? []),
                'payload' => $message['payload'] ?? null,
            ];
        }

        return $this->client->request('POST', '/partner/rooms/emit', ['rooms' => $rooms]) ?? [];
    }

    /**
     * Emit the same event(s) + payload to several rooms in a single request.
     *
     * ```php
     * $nexus->realtime()->emitToRooms(['orders:1', 'orders:2'], 'location', $payload);
     * ```
     *
     * @param list<string>         $rooms
     * @param string|list<string>  $events
     *
     * @return array<mixed> { ok, count, results }
     */
    public function emitToRooms(array $rooms, string|array $events, mixed $payload = null): array
    {
        $messages = [];
        foreach ($rooms as $room) {
            $messages[] = ['room' => (string) $room, 'events' => $events, 'payload' => $payload];
        }

        return $this->broadcast($messages);
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

    /**
     * @param string|list<string> $events
     *
     * @return list<string>
     */
    private function normalizeEvents(string|array $events): array
    {
        return is_string($events) ? [$events] : array_values($events);
    }
}

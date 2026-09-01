<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

use Inverge\Nexus\RoomMessage;

/**
 * Realtime (rooms) — server-side emit + room management over the `/partner/rooms`
 * HTTP surface. Emitting broadcasts to every client currently joined to the room.
 */
final class Realtime extends AbstractResource
{
    /**
     * Emit one or more named events to a room. Pass a {@see RoomMessage}, or a
     * room name plus events/payload.
     *
     * @param RoomMessage|string   $room    a message, or the room name
     * @param string|list<string>  $events  events (ignored when $room is a RoomMessage)
     * @param mixed                $payload JSON-serialisable payload (ignored when $room is a RoomMessage)
     *
     * @return array<mixed> the ack: { ok, room, related, events, recipients }
     */
    public function emit(RoomMessage|string $room, string|array $events = [], mixed $payload = null): array
    {
        $message = $room instanceof RoomMessage ? $room : new RoomMessage($room, $events, $payload);

        return $this->client->request('POST', '/partner/rooms/' . rawurlencode($message->room) . '/emit', [
            'events' => $message->events,
            'payload' => $message->payload,
        ]) ?? [];
    }

    /**
     * Broadcast to many rooms (each with its own events/payload) in one request.
     * Each item is a {@see RoomMessage} or a loose array (`room`/`name`,
     * `event`/`events`, `payload`).
     *
     * @param iterable<RoomMessage|array<string, mixed>> $messages
     *
     * @return array<mixed> { ok, count, results }
     */
    public function broadcast(iterable $messages): array
    {
        $rooms = [];
        foreach ($messages as $message) {
            $rooms[] = ($message instanceof RoomMessage ? $message : RoomMessage::fromArray($message))->toArray();
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
            $messages[] = new RoomMessage((string) $room, $events, $payload);
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
}

<?php

declare(strict_types=1);

namespace Inverge\Nexus;

/**
 * A realtime message: which room, which event(s), and the payload. Use it with
 * {@see \Inverge\Nexus\Resource\Realtime::emit()} and `broadcast()` instead of
 * loose arrays.
 *
 * ```php
 * use Inverge\Nexus\RoomMessage;
 *
 * $nexus->realtime()->emit(new RoomMessage('orders:42', 'status', ['state' => 'shipped']));
 * $nexus->realtime()->broadcast([
 *     new RoomMessage('orders:1', 'location', ['lat' => 1]),
 *     new RoomMessage('orders:2', ['location', 'eta'], ['lat' => 2]),
 * ]);
 * ```
 */
final class RoomMessage
{
    /** @var list<string> */
    public readonly array $events;

    /** @param string|list<string> $events */
    public function __construct(
        public readonly string $room,
        string|array $events,
        public readonly mixed $payload = null,
    ) {
        $this->events = is_string($events) ? [$events] : array_values($events);
    }

    /** @param string|list<string> $events */
    public static function make(string $room, string|array $events, mixed $payload = null): self
    {
        return new self($room, $events, $payload);
    }

    /**
     * Build from a loose array (`room`/`name`, `event`/`events`, `payload`) —
     * used to keep array input working alongside the DTO.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['room'] ?? $data['name'] ?? ''),
            $data['events'] ?? $data['event'] ?? [],
            $data['payload'] ?? null,
        );
    }

    /** @return array{name: string, events: list<string>, payload: mixed} */
    public function toArray(): array
    {
        return [
            'name' => $this->room,
            'events' => $this->events,
            'payload' => $this->payload,
        ];
    }
}

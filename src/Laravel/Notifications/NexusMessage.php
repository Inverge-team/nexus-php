<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Inverge\Nexus\NexusClient;
use Inverge\Nexus\RoomMessage;

/**
 * The message a notification's `toNexus()` returns. A fluent builder that can
 * do several things at once — track an event, emit to a room, write a log, and
 * capture an error — all attributed to the notifiable.
 *
 * ```php
 * public function toNexus(object $notifiable): NexusMessage
 * {
 *     return NexusMessage::create()
 *         ->event('order_shipped', ['order' => $this->order->id])
 *         ->emit("orders:{$this->order->customer_id}", 'status', ['state' => 'shipped'])
 *         ->info("Order {$this->order->id} shipped");
 * }
 * ```
 */
final class NexusMessage
{
    private ?string $distinctId = null;

    /** @var array<string, mixed> */
    private array $identity = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $ops = [];

    public static function create(): self
    {
        return new self();
    }

    /** Attribute everything in this message to a user. */
    public function to(string $distinctId): self
    {
        $this->distinctId = $distinctId;

        return $this;
    }

    /**
     * Extra journey identity (sessionKey, deviceKey, …) applied to event/log/error.
     *
     * @param array<string, mixed> $identity
     */
    public function identity(array $identity): self
    {
        $this->identity = array_merge($this->identity, $identity);

        return $this;
    }

    // ---- operations ----

    /** @param array<string, mixed> $properties */
    public function event(string $name, array $properties = []): self
    {
        $this->ops[] = ['event', ['name' => $name, 'properties' => $properties]];

        return $this;
    }

    /**
     * @param RoomMessage|string  $room
     * @param string|list<string> $events
     */
    public function emit(RoomMessage|string $room, string|array $events = [], mixed $payload = null): self
    {
        $message = $room instanceof RoomMessage ? $room : new RoomMessage($room, $events, $payload);
        $this->ops[] = ['emit', ['message' => $message]];

        return $this;
    }

    /**
     * Emit the same event(s) + payload to several rooms in one request.
     *
     * @param list<string>        $rooms
     * @param string|list<string> $events
     */
    public function emitToRooms(array $rooms, string|array $events, mixed $payload = null): self
    {
        $this->ops[] = ['emitToRooms', ['rooms' => $rooms, 'events' => $events, 'payload' => $payload]];

        return $this;
    }

    /**
     * Broadcast to many rooms, each with its own events/payload, in one request.
     *
     * @param list<array<string, mixed>> $messages
     */
    public function broadcast(array $messages): self
    {
        $this->ops[] = ['broadcast', ['messages' => $messages]];

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): self
    {
        $this->ops[] = ['log', ['level' => $level, 'message' => $message, 'context' => $context]];

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): self
    {
        return $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): self
    {
        return $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): self
    {
        return $this->log('warn', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): self
    {
        return $this->log('error', $message, $context);
    }

    /**
     * Capture an error into the Errors product (not a log line).
     *
     * @param array<string, mixed> $options
     */
    public function captureError(string $message, array $options = []): self
    {
        $this->ops[] = ['error', ['message' => $message, 'options' => $options]];

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function captureException(\Throwable $exception, array $options = []): self
    {
        $this->ops[] = ['exception', ['throwable' => $exception, 'options' => $options]];

        return $this;
    }

    /**
     * Apply the notifiable's `routeNotificationForNexus()` result as defaults —
     * a distinctId string, or an identity array. Explicit `to()`/`identity()`
     * on the message win.
     *
     * @internal used by {@see NexusChannel}
     */
    public function applyRoute(mixed $route): void
    {
        if (is_string($route)) {
            $this->distinctId ??= $route;

            return;
        }

        if (is_array($route)) {
            if ($this->distinctId === null && isset($route['distinctId'])) {
                $this->distinctId = (string) $route['distinctId'];
            }
            foreach ($route as $key => $value) {
                if ($key !== 'distinctId' && !isset($this->identity[$key])) {
                    $this->identity[$key] = $value;
                }
            }
        }
    }

    /** Execute every queued operation against the client. */
    public function send(NexusClient $client): void
    {
        foreach ($this->ops as [$type, $args]) {
            match ($type) {
                'event' => $client->events()->capture($args['name'], $args['properties'], $this->identityOptions()),
                'emit' => $client->realtime()->emit($args['message']),
                'emitToRooms' => $client->realtime()->emitToRooms($args['rooms'], $args['events'], $args['payload']),
                'broadcast' => $client->realtime()->broadcast($args['messages']),
                'log' => $client->logs()->log(
                    $args['level'],
                    $args['message'],
                    $this->identityOptions($args['context'] !== [] ? ['context' => $args['context']] : []),
                ),
                'error' => $client->errors()->capture($args['message'], $this->identityOptions($args['options'])),
                'exception' => $client->errors()->captureException($args['throwable'], $this->identityOptions($args['options'])),
                default => null,
            };
        }
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function identityOptions(array $extra = []): array
    {
        $options = $this->identity;
        if ($this->distinctId !== null) {
            $options['distinctId'] = $this->distinctId;
        }

        return array_merge($options, $extra);
    }
}

<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\Messenger;

use Inverge\Nexus\Contracts\Dispatcher;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A {@see Dispatcher} that hands delivery to the Symfony message bus — parity
 * with Laravel's queued dispatcher. Fire-and-forget: returns null. Route
 * {@see NexusDeliveryMessage} to an async transport for true off-request delivery.
 */
final class MessengerDispatcher implements Dispatcher
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function dispatch(string $method, string $path, ?array $json = null, array $headers = []): ?array
    {
        $this->bus->dispatch(new NexusDeliveryMessage($method, $path, $json, $headers));

        return null;
    }
}

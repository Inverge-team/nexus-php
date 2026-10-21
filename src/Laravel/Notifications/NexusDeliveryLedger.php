<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

/**
 * Remembers which products of a notification were already delivered, so when a
 * queued notification is retried the all-in-one {@see NexusChannel} re-sends
 * only what failed — never a second push for a Live Activity hiccup.
 * Laravel binds it to the cache ({@see CacheDeliveryLedger}).
 */
interface NexusDeliveryLedger
{
    public function delivered(string $key): bool;

    public function markDelivered(string $key): void;
}

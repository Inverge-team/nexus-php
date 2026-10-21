<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

/**
 * {@see NexusDeliveryLedger} on a Laravel cache store (`has` / `put`). Entries
 * live a day — far longer than any queue retry window.
 *
 * @internal bound by the service provider
 */
final class CacheDeliveryLedger implements NexusDeliveryLedger
{
    private const TTL_SECONDS = 86400;

    /** @param object $cache an Illuminate\Contracts\Cache\Repository */
    public function __construct(private readonly object $cache)
    {
    }

    public function delivered(string $key): bool
    {
        try {
            return (bool) $this->cache->has($key);
        } catch (\Throwable) {
            return false; // a cache outage must not block delivery
        }
    }

    public function markDelivered(string $key): void
    {
        try {
            $this->cache->put($key, 1, self::TTL_SECONDS);
        } catch (\Throwable) {
            // best-effort
        }
    }
}

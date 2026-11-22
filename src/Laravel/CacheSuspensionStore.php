<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Inverge\Nexus\Contracts\SuspensionStore;

/**
 * Keeps the billing-suspension pause in Laravel's cache, so web requests, queue
 * workers and the scheduler — on every host sharing the cache — pause together.
 * A cache that fails is treated as empty; each process still pauses itself.
 */
final class CacheSuspensionStore implements SuspensionStore
{
    private const PREFIX = 'inverge-nexus:suspended:';

    public function __construct(private readonly Repository $cache)
    {
    }

    public function get(string $key): ?int
    {
        try {
            $value = $this->cache->get(self::PREFIX . $key);
        } catch (\Throwable) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public function put(string $key, int $until): void
    {
        try {
            // Outlive the pause a little, so the probe still finds it expired.
            $this->cache->put(self::PREFIX . $key, $until, max(60, $until - time() + 60));
        } catch (\Throwable) {
        }
    }

    public function forget(string $key): void
    {
        try {
            $this->cache->forget(self::PREFIX . $key);
        } catch (\Throwable) {
        }
    }
}

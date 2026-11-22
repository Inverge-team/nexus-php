<?php

declare(strict_types=1);

namespace Inverge\Nexus\Contracts;

/**
 * Where the billing-suspension pause is remembered, so every PHP process that
 * shares it (FPM workers, queue workers, cron) stops calling the API together —
 * see {@see \Inverge\Nexus\Http\BillingSuspension}.
 *
 * Implementations must never throw; a store that can't be read means "not
 * suspended" (the process still remembers its own pause in memory).
 */
interface SuspensionStore
{
    /** The unix time the pause ends, or null when there is none. */
    public function get(string $key): ?int;

    /** Pause until `$until` (unix time). */
    public function put(string $key, int $until): void;

    public function forget(string $key): void;
}

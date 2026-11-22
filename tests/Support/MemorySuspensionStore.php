<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests\Support;

use Inverge\Nexus\Contracts\SuspensionStore;

/** In-memory {@see SuspensionStore} — one instance stands for a shared cache. */
final class MemorySuspensionStore implements SuspensionStore
{
    /** @var array<string, int> */
    public array $values = [];

    public function get(string $key): ?int
    {
        return $this->values[$key] ?? null;
    }

    public function put(string $key, int $until): void
    {
        $this->values[$key] = $until;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    /** Move every pause into the past, as if it had run out. */
    public function expire(): void
    {
        foreach ($this->values as $key => $_) {
            $this->values[$key] = time() - 1;
        }
    }
}

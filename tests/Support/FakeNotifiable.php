<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests\Support;

/** A notifiable the way Laravel calls it: routeNotificationFor($channel, $notification). */
class FakeNotifiable
{
    /** @param array<string, mixed> $routes */
    public function __construct(private array $routes = [])
    {
    }

    public function routeNotificationFor(string $driver, object $notification): mixed
    {
        return $this->routes[$driver] ?? null;
    }
}

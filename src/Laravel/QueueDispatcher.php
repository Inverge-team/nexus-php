<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel;

use Inverge\Nexus\Contracts\Dispatcher;

/**
 * A {@see Dispatcher} that hands delivery to Laravel's queue via {@see NexusJob}.
 * Fire-and-forget: returns null immediately. Use only for telemetry that doesn't
 * need a response (events, logs, errors, links, realtime emit).
 */
final class QueueDispatcher implements Dispatcher
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
    ) {
    }

    public function dispatch(string $method, string $path, ?array $json = null, array $headers = []): ?array
    {
        $pending = NexusJob::dispatch($method, $path, $json, $headers);

        if ($this->connection !== null) {
            $pending->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $pending->onQueue($this->queue);
        }

        return null;
    }
}

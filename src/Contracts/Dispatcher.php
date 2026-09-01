<?php

declare(strict_types=1);

namespace Inverge\Nexus\Contracts;

/**
 * Delivers a partner-API request. The default {@see \Inverge\Nexus\Http\SyncDispatcher}
 * sends immediately over a {@see Transport}; integrations can swap in an async
 * one (e.g. a Laravel queue) to keep delivery off the request hot path.
 */
interface Dispatcher
{
    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     *
     * @return array<mixed>|null the decoded response, or null when delivery is deferred
     */
    public function dispatch(string $method, string $path, ?array $json = null, array $headers = []): ?array;
}

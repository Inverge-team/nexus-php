<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Inverge\Nexus\Http\SyncDispatcher;

/**
 * Queued delivery of a single Nexus request — used by {@see QueueDispatcher} so
 * telemetry (events, logs, errors) leaves the request hot path. Delivered
 * synchronously inside the worker via the bound {@see SyncDispatcher}.
 */
final class NexusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?array $json = null,
        public array $headers = [],
    ) {
    }

    public function handle(SyncDispatcher $dispatcher): void
    {
        $dispatcher->dispatch($this->method, $this->path, $this->json, $this->headers);
    }
}

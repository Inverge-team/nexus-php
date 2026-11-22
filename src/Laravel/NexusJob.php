<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\BillingSuspendedException;
use Inverge\Nexus\Exception\TransportException;
use Inverge\Nexus\Http\BillingSuspension;
use Inverge\Nexus\Http\SyncDispatcher;

/**
 * Queued delivery of a single Nexus request — used by {@see QueueDispatcher} so
 * telemetry (events, logs, errors) leaves the request hot path. Delivered
 * synchronously inside the worker via the bound {@see SyncDispatcher}.
 *
 * **The job never fails.** A failed job is reported through the app's
 * exception handler — which, with Nexus error capture on, queues ANOTHER
 * NexusJob to report it, which fails the same way: one refused request becomes
 * an endless stream (this is how a suspended account got ~900 requests a
 * minute). So a 4xx — including 402 billing suspension — drops the telemetry,
 * and a 429/5xx/network failure is retried with `release()` (which reports
 * nothing) and dropped after {@see $tries}.
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
        if (BillingSuspension::isSuspensionReport($this->path, $this->json)) {
            return; // queued by an older SDK during a suspension — reporting it is the loop
        }

        try {
            $dispatcher->dispatch($this->method, $this->path, $this->json, $this->headers);
        } catch (BillingSuspendedException) {
            return; // the breaker holds everything back until the pause ends
        } catch (ApiException $e) {
            if ($e->status === 429 || $e->status >= 500) {
                $this->retryLater();
            }
            // other 4xx: the request itself is wrong — retrying can't fix it
        } catch (TransportException) {
            $this->retryLater();
        } catch (\Throwable) {
            // never fail: see the class note
        }
    }

    private function retryLater(): void
    {
        $attempt = $this->job !== null ? $this->attempts() : $this->tries;
        if ($attempt < $this->tries) {
            $this->release($this->backoff * $attempt);
        }
    }
}

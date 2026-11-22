<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\Messenger;

use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\BillingSuspendedException;
use Inverge\Nexus\Http\BillingSuspension;
use Inverge\Nexus\Http\SyncDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Delivers a {@see NexusDeliveryMessage} synchronously inside the worker. The
 * bundle also tags this as a `messenger.message_handler` explicitly, so it works
 * whether or not attribute autoconfiguration is enabled.
 *
 * Billing suspension and other 4xx answers drop the message (retrying can't
 * succeed, and each failure would be logged → reported → queued again); a
 * 429/5xx/network failure is rethrown so Messenger's retry strategy applies.
 */
#[AsMessageHandler]
final class NexusDeliveryHandler
{
    public function __construct(private readonly SyncDispatcher $dispatcher)
    {
    }

    public function __invoke(NexusDeliveryMessage $message): void
    {
        if (BillingSuspension::isSuspensionReport($message->path, $message->json)) {
            return;
        }
        try {
            $this->dispatcher->dispatch($message->method, $message->path, $message->json, $message->headers);
        } catch (BillingSuspendedException) {
            return;
        } catch (ApiException $e) {
            if ($e->status === 429 || $e->status >= 500) {
                throw $e;
            }
        }
    }
}

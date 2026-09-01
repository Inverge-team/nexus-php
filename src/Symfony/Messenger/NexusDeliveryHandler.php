<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\Messenger;

use Inverge\Nexus\Http\SyncDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Delivers a {@see NexusDeliveryMessage} synchronously inside the worker. The
 * bundle also tags this as a `messenger.message_handler` explicitly, so it works
 * whether or not attribute autoconfiguration is enabled.
 */
#[AsMessageHandler]
final class NexusDeliveryHandler
{
    public function __construct(private readonly SyncDispatcher $dispatcher)
    {
    }

    public function __invoke(NexusDeliveryMessage $message): void
    {
        $this->dispatcher->dispatch($message->method, $message->path, $message->json, $message->headers);
    }
}

<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\EventListener;

use Inverge\Nexus\NexusClient;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reports every unhandled kernel exception to Nexus Errors. Registered
 * automatically by {@see \Inverge\Nexus\Symfony\NexusBundle} when
 * `nexus.capture_errors` is true. Never rethrows or alters the response.
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly NexusClient $nexus)
    {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        // Low priority so it runs after the app's own handlers have had a look.
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        try {
            $request = $event->getRequest();
            $this->nexus->errors()->captureException($event->getThrowable(), [
                'handled' => false,
                'url' => $request->getUri(),
            ]);
        } catch (\Throwable) {
            // never let telemetry interfere with error handling
        }
    }
}

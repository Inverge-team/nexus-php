<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\ClientIp;

use Inverge\Nexus\Support\ClientIp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets {@see ClientIp} from the main request. `getClientIp()` honours
 * `framework.trusted_proxies`, so behind a load balancer it is the user, not
 * the balancer. Set on every main request, so a long-running worker
 * (RoadRunner, FrankenPHP) never keeps the previous user's address.
 */
final class ClientIpSubscriber implements EventSubscriberInterface
{
    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 256]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            ClientIp::set($event->getRequest()->getClientIp());
        }
    }
}

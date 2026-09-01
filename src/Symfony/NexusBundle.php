<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony;

use Inverge\Nexus\Symfony\DependencyInjection\NexusExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony integration. Register in `config/bundles.php`:
 *
 * ```php
 * Inverge\Nexus\Symfony\NexusBundle::class => ['all' => true],
 * ```
 *
 * Then autowire {@see \Inverge\Nexus\NexusClient} into your services/controllers.
 */
final class NexusBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new NexusExtension();
    }
}

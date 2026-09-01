<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\DependencyInjection;

use Inverge\Nexus\Config;
use Inverge\Nexus\Monolog\NexusLogHandler;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Symfony\EventListener\ExceptionSubscriber;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers {@see NexusClient} as an autowireable service built from the
 * bundle's configuration.
 */
final class NexusExtension extends Extension
{
    /** @param array<array-key, mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $configDefinition = new Definition(Config::class, [
            $config['api_key'],
            $config['base_url'],
            (float) $config['timeout'],
        ]);

        $clientDefinition = new Definition(NexusClient::class, [$configDefinition]);
        $clientDefinition->setPublic(true);

        $container->setDefinition(NexusClient::class, $clientDefinition);
        $container->setAlias('nexus', NexusClient::class)->setPublic(true);

        // Auto error capture via a kernel.exception subscriber.
        if ($config['capture_errors']) {
            $subscriber = new Definition(ExceptionSubscriber::class, [new Reference(NexusClient::class)]);
            $subscriber->addTag('kernel.event_subscriber');
            $container->setDefinition(ExceptionSubscriber::class, $subscriber);
        }

        // A Monolog handler service for log forwarding. Add it to your
        // monolog.yaml `handlers` (type: service, id: Inverge\Nexus\Monolog\NexusLogHandler).
        // Exceptions are captured by the subscriber above, so keep it off here.
        $logHandler = new Definition(NexusLogHandler::class, [new Reference(NexusClient::class), false]);
        $container->setDefinition(NexusLogHandler::class, $logHandler);
    }

    public function getAlias(): string
    {
        return 'nexus';
    }
}

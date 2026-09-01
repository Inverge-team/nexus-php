<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\DependencyInjection;

use Inverge\Nexus\Config;
use Inverge\Nexus\NexusClient;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;

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
    }

    public function getAlias(): string
    {
        return 'nexus';
    }
}

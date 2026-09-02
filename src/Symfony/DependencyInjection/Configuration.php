<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Config tree for the `nexus` bundle (config/packages/nexus.yaml):
 *
 * ```yaml
 * nexus:
 *     api_key: '%env(NEXUS_API_KEY)%'
 *     base_url: '%env(default:nexus_default_base:NEXUS_BASE_URL)%'
 *     timeout: 10.0
 * ```
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('nexus');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('api_key')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Project/environment API key (nxs_...).')
                ->end()
                ->scalarNode('base_url')
                    ->defaultValue('https://nexus.inverge.net')
                    ->info('Nexus origin; partner endpoints live under /partner.')
                ->end()
                ->floatNode('timeout')
                    ->defaultValue(10.0)
                ->end()
                ->booleanNode('capture_errors')
                    ->defaultTrue()
                    ->info('Auto-report unhandled kernel exceptions to Nexus Errors.')
                ->end()
                ->booleanNode('async')
                    ->defaultFalse()
                    ->info('Deliver telemetry via Symfony Messenger instead of inline (requires symfony/messenger).')
                ->end()
                ->scalarNode('bus')
                    ->defaultValue('message_bus')
                    ->info('Message bus service id used for async delivery.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}

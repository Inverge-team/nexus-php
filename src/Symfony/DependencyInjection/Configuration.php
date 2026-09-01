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
                    ->defaultValue('https://api.nexus.inverge.net')
                    ->info('Nexus origin; partner endpoints live under /partner.')
                ->end()
                ->floatNode('timeout')
                    ->defaultValue(10.0)
                ->end()
            ->end();

        return $treeBuilder;
    }
}

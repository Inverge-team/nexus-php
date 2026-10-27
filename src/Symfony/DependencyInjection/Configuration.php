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
                    ->defaultValue('https://services.inverge.net')
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
                ->arrayNode('telescope')
                    ->info('Request-journey tracing (Nexus Telescope).')
                    ->canBeDisabled()
                    ->children()
                        ->scalarNode('service')->defaultNull()->info('Service name shown in traces.')->end()
                        ->scalarNode('release')->defaultNull()->end()
                        ->floatNode('sample_rate')->defaultNull()->info('null = the console\'s sample rate.')->end()
                        ->floatNode('timeout')->defaultValue(3.0)->end()
                        ->arrayNode('ignore_paths')->scalarPrototype()->end()->defaultValue(['/_wdt*', '/_profiler*', '/health*'])->end()
                        ->arrayNode('ignore_statuses')->info('Response statuses never sent: 200, 2xx or 301-304.')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('ignore_commands')->scalarPrototype()->end()->defaultValue(['messenger:consume', 'cache:*', 'assets:*', 'debug:*', 'lint:*', 'list', 'help', 'nexus:*'])->end()
                        ->arrayNode('redact_keys')->scalarPrototype()->end()->defaultValue([])->end()
                        ->booleanNode('capture_response_bodies')->defaultTrue()->end()
                        ->integerNode('body_size_limit')->defaultValue(64)->end()
                        ->booleanNode('doctrine')->defaultTrue()->info('Record Doctrine DBAL queries.')->end()
                        ->booleanNode('messenger')->defaultTrue()->info('Record Messenger messages as jobs.')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}

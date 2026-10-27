<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\DependencyInjection;

use Inverge\Nexus\Config;
use Inverge\Nexus\Http\SyncDispatcher;
use Inverge\Nexus\Monolog\NexusLogHandler;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Symfony\EventListener\ExceptionSubscriber;
use Inverge\Nexus\Symfony\Messenger\MessengerDispatcher;
use Inverge\Nexus\Symfony\Messenger\NexusDeliveryHandler;
use Inverge\Nexus\Symfony\Telescope\DoctrineMiddleware;
use Inverge\Nexus\Symfony\Telescope\MessengerSubscriber;
use Inverge\Nexus\Symfony\Telescope\TelescopeSubscriber;
use Inverge\Nexus\Telescope\Telescope;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers {@see NexusClient} (autowireable) from config, plus — when enabled —
 * auto error capture ({@see ExceptionSubscriber}), a Monolog handler service,
 * and async delivery via Symfony Messenger.
 */
final class NexusExtension extends Extension
{
    /** @param array<array-key, mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setDefinition(Config::class, new Definition(Config::class, [
            $config['api_key'],
            $config['base_url'],
            (float) $config['timeout'],
        ]));

        $container->setDefinition(SyncDispatcher::class, new Definition(SyncDispatcher::class, [
            new Reference(Config::class),
        ]));

        $clientDefinition = new Definition(NexusClient::class, [
            new Reference(Config::class),
            null,
            new Reference(SyncDispatcher::class),
        ]);
        $clientDefinition->setPublic(true);
        $container->setDefinition(NexusClient::class, $clientDefinition);
        $container->setAlias('nexus', NexusClient::class)->setPublic(true);

        // Which client telemetry (errors/logs) uses — the async one when enabled.
        $telemetryClient = new Reference(NexusClient::class);

        if ($config['async']) {
            $container->setDefinition(MessengerDispatcher::class, new Definition(MessengerDispatcher::class, [
                new Reference($config['bus']),
            ]));

            $handler = new Definition(NexusDeliveryHandler::class, [new Reference(SyncDispatcher::class)]);
            $handler->addTag('messenger.message_handler');
            $container->setDefinition(NexusDeliveryHandler::class, $handler);

            $asyncClient = new Definition(NexusClient::class, [
                new Reference(Config::class),
                null,
                new Reference(MessengerDispatcher::class),
            ]);
            $asyncClient->setPublic(true);
            $container->setDefinition('nexus.async', $asyncClient);

            $telemetryClient = new Reference('nexus.async');
        }

        if ($config['capture_errors']) {
            $subscriber = new Definition(ExceptionSubscriber::class, [$telemetryClient]);
            $subscriber->addTag('kernel.event_subscriber');
            $container->setDefinition(ExceptionSubscriber::class, $subscriber);
        }

        // Monolog handler service — add it to monolog.yaml `handlers`
        // (type: service, id: Inverge\Nexus\Monolog\NexusLogHandler). Exceptions
        // are handled by the subscriber, so keep capture off here (2nd arg false).
        $container->setDefinition(NexusLogHandler::class, new Definition(NexusLogHandler::class, [
            $telemetryClient,
            false,
        ]));

        $this->loadTelescope((array) ($config['telescope'] ?? []), $container);
    }

    /** @param array<string, mixed> $t */
    private function loadTelescope(array $t, ContainerBuilder $container): void
    {
        if (!($t['enabled'] ?? true)) {
            return;
        }
        $telescope = (new Definition(Telescope::class))
            ->setFactory([new Reference(NexusClient::class), 'telescope'])
            ->setArguments([[
                'service' => $t['service'] ?? null,
                'release' => $t['release'] ?? null,
                'sample_rate' => $t['sample_rate'] ?? null,
                'timeout' => (float) ($t['timeout'] ?? 3.0),
                'ignore_paths' => $t['ignore_paths'] ?? [],
                'ignore_statuses' => $t['ignore_statuses'] ?? [],
                'redact_keys' => $t['redact_keys'] ?? [],
                'capture_response_bodies' => (bool) ($t['capture_response_bodies'] ?? true),
                'body_size_limit' => (int) ($t['body_size_limit'] ?? 64),
                'cache_dir' => '%kernel.cache_dir%',
            ]])
            ->setPublic(true);
        $container->setDefinition(Telescope::class, $telescope);

        $container->setDefinition(TelescopeSubscriber::class, (new Definition(TelescopeSubscriber::class, [
            new Reference(Telescope::class),
            $t['ignore_commands'] ?? [],
        ]))->addTag('kernel.event_subscriber'));

        if (($t['messenger'] ?? true) && interface_exists('Symfony\Component\Messenger\Stamp\StampInterface')) {
            $container->setDefinition(MessengerSubscriber::class, (new Definition(MessengerSubscriber::class, [
                new Reference(Telescope::class),
            ]))->addTag('kernel.event_subscriber'));
        }

        if (($t['doctrine'] ?? true) && interface_exists('Doctrine\DBAL\Driver\Middleware')
            && class_exists('Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware')) {
            $container->setDefinition(DoctrineMiddleware::class, (new Definition(DoctrineMiddleware::class, [
                new Reference(Telescope::class),
            ]))->addTag('doctrine.middleware'));
        }
    }

    public function getAlias(): string
    {
        return 'nexus';
    }
}

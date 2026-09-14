<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Inverge\Nexus\Config;
use Illuminate\Notifications\ChannelManager;
use Inverge\Nexus\Http\SyncDispatcher;
use Inverge\Nexus\Laravel\Notifications\NexusChannel;
use Inverge\Nexus\Monolog\NexusLogHandler;
use Inverge\Nexus\NexusClient;
use Monolog\Level;
use Monolog\Logger;

/**
 * Laravel integration. Auto-discovered via composer `extra.laravel`.
 *
 * Bindings:
 *  - {@see NexusClient} / `nexus` — synchronous client (facade + type-hint)
 *  - `nexus.queue` — a client that delivers telemetry via the queue ({@see QueueDispatcher})
 *
 * When `nexus.logging.enabled` is true it attaches {@see NexusLogHandler} to the
 * default log channel — forwarding logs to Nexus Logs and (via
 * `nexus.capture_errors`) unhandled exceptions to Nexus Errors.
 */
final class NexusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/nexus.php', 'nexus');

        $this->app->singleton(SyncDispatcher::class, static function (Application $app): SyncDispatcher {
            return new SyncDispatcher(self::config($app));
        });

        $this->app->singleton(NexusClient::class, static function (Application $app): NexusClient {
            return new NexusClient(self::config($app), null, $app->make(SyncDispatcher::class));
        });

        $this->app->alias(NexusClient::class, 'nexus');

        $this->app->singleton('nexus.queue', static function (Application $app): NexusClient {
            /** @var array<string, mixed> $queue */
            $queue = $app['config']['nexus']['queue'] ?? [];

            return $app->make(NexusClient::class)->withDispatcher(new QueueDispatcher(
                $queue['connection'] ?? null,
                $queue['queue'] ?? null,
            ));
        });

        // Pre-register a `nexus` log channel so `LOG_CHANNEL=nexus` (or adding
        // 'nexus' to a stack) works with ZERO edits to config/logging.php. The
        // user can still override this block in their own logging config.
        $channels = (array) ($this->app['config']['logging.channels'] ?? []);
        if (!isset($channels['nexus'])) {
            $this->app['config']->set('logging.channels.nexus', [
                'driver' => 'nexus',
                'level' => env('NEXUS_LOG_LEVEL', env('LOG_LEVEL', 'debug')),
            ]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/nexus.php' => $this->app->configPath('nexus.php'),
            ], 'nexus-config');

            $this->commands([NexusTestCommand::class]);
        }

        $this->registerNotificationChannel();
        $this->registerLogChannel();
        $this->attachLogHandler();
    }

    /**
     * Register the `nexus` log-channel driver. With it a user can send logs to
     * Nexus INSTEAD of the local log file — set `LOG_CHANNEL=nexus` (nothing
     * hits laravel.log), or keep the file and add both to a stack:
     *
     *   'channels' => [
     *       'stack' => ['driver' => 'stack', 'channels' => ['single', 'nexus']],
     *       'nexus' => ['driver' => 'nexus', 'level' => 'debug'],  // optional; auto-registered
     *   ]
     *
     * Per-channel config keys: `level`, `flush_at`, `capture_errors`, `queue`.
     */
    private function registerLogChannel(): void
    {
        try {
            $log = $this->app->make('log');
            if (!method_exists($log, 'extend')) {
                return;
            }

            $log->extend('nexus', function (Application $app, array $config): Logger {
                /** @var array<string, mixed> $nexusCfg */
                $nexusCfg = $app['config']['nexus'] ?? [];

                $useQueue = (bool) ($config['queue'] ?? ($nexusCfg['queue']['enabled'] ?? false));
                $client = $useQueue ? $app->make('nexus.queue') : $app->make(NexusClient::class);

                $handler = new NexusLogHandler(
                    nexus: $client,
                    captureExceptions: (bool) ($config['capture_errors'] ?? ($nexusCfg['capture_errors'] ?? true)),
                    flushAt: (int) ($config['flush_at'] ?? ($nexusCfg['logging']['flush_at'] ?? 50)),
                    level: self::level((string) ($config['level'] ?? 'debug')),
                );

                return new Logger('nexus', [$handler]);
            });
        } catch (\Throwable) {
            // best-effort — never break app boot over logging wiring
        }
    }

    /**
     * Register the `'nexus'` notification driver so `via()` can return `'nexus'`.
     * (Referencing NexusChannel::class in `via()` already works without this.)
     */
    private function registerNotificationChannel(): void
    {
        if (!class_exists(ChannelManager::class)) {
            return;
        }

        $this->app->resolving(ChannelManager::class, static function (ChannelManager $manager, $app): void {
            $manager->extend('nexus', static fn ($app) => $app->make(NexusChannel::class));
        });
    }

    private function attachLogHandler(): void
    {
        /** @var array<string, mixed> $config */
        $config = $this->app['config']['nexus'] ?? [];

        if (!($config['logging']['enabled'] ?? false)) {
            return;
        }

        try {
            $useQueue = (bool) ($config['queue']['enabled'] ?? false);
            $client = $useQueue ? $this->app->make('nexus.queue') : $this->app->make(NexusClient::class);

            $handler = new NexusLogHandler(
                nexus: $client,
                captureExceptions: (bool) ($config['capture_errors'] ?? true),
                flushAt: (int) ($config['logging']['flush_at'] ?? 50),
                level: self::level((string) ($config['logging']['level'] ?? 'debug')),
            );

            $logger = $this->app['log']->channel()->getLogger();
            if ($logger instanceof Logger) {
                $logger->pushHandler($handler);
            }
        } catch (\Throwable) {
            // best-effort — never break app boot over logging wiring
        }
    }

    private static function config(Application $app): Config
    {
        /** @var array<string, mixed> $c */
        $c = $app['config']['nexus'] ?? [];

        return new Config(
            apiKey: (string) ($c['api_key'] ?? ''),
            baseUrl: (string) ($c['base_url'] ?? 'https://services.inverge.net'),
            timeout: (float) ($c['timeout'] ?? 10.0),
        );
    }

    private static function level(string $name): Level
    {
        return match (strtolower($name)) {
            'trace', 'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warn', 'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency', 'fatal' => Level::Emergency,
            default => Level::Debug,
        };
    }

    /** @return array<int, string> */
    public function provides(): array
    {
        return [NexusClient::class, 'nexus', 'nexus.queue', SyncDispatcher::class];
    }
}

<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Inverge\Nexus\Config;
use Inverge\Nexus\NexusClient;

/**
 * Laravel integration. Auto-discovered via composer `extra.laravel`. Binds a
 * singleton {@see NexusClient} (resolvable by type-hint or the `nexus` alias /
 * {@see Nexus} facade) from `config/nexus.php`.
 */
final class NexusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/nexus.php', 'nexus');

        $this->app->singleton(NexusClient::class, static function (Application $app): NexusClient {
            /** @var array<string, mixed> $config */
            $config = $app['config']['nexus'] ?? [];

            return new NexusClient(new Config(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? 'https://api.nexus.inverge.net'),
                timeout: (float) ($config['timeout'] ?? 10.0),
            ));
        });

        $this->app->alias(NexusClient::class, 'nexus');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/nexus.php' => $this->app->configPath('nexus.php'),
            ], 'nexus-config');
        }
    }

    /** @return array<int, string> */
    public function provides(): array
    {
        return [NexusClient::class, 'nexus'];
    }
}

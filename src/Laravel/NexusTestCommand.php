<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel;

use Illuminate\Console\Command;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\NexusException;
use Inverge\Nexus\NexusClient;

/**
 * `php artisan nexus:test` — verify the SDK config and connectivity by making a
 * real authenticated call (lists rooms, or emits a test event with `--room`).
 */
final class NexusTestCommand extends Command
{
    protected $signature = 'nexus:test {--room= : Emit a test event to this room instead of just checking auth}';

    protected $description = 'Verify the Nexus SDK configuration and connectivity.';

    public function handle(NexusClient $nexus): int
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('nexus');

        $key = (string) ($config['api_key'] ?? '');
        $this->line('Base URL : <info>' . ($config['base_url'] ?? '') . '</info>');
        $this->line('API key  : <info>' . ($key !== '' ? substr($key, 0, 8) . '…' . substr($key, -4) : '<missing>') . '</info>');

        if ($key === '') {
            $this->error('NEXUS_API_KEY is not set. Add it to your .env.');

            return self::FAILURE;
        }

        try {
            $room = $this->option('room');

            if (is_string($room) && $room !== '') {
                $result = $nexus->realtime()->emit($room, 'nexus.test', ['at' => date('c')]);
                $this->info("✓ Emitted a test event to \"{$room}\" — recipients: " . ($result['recipients'] ?? 0));
            } else {
                $rooms = $nexus->realtime()->rooms();
                $this->info('✓ Connected and authenticated. Registered rooms: ' . count($rooms));
            }

            return self::SUCCESS;
        } catch (ApiException $e) {
            $suffix = $e->errorCode !== null ? " ({$e->errorCode})" : '';
            $this->error("✗ API returned {$e->status}: {$e->getMessage()}{$suffix}");

            return self::FAILURE;
        } catch (NexusException $e) {
            $this->error("✗ {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}

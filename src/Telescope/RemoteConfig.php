<?php

declare(strict_types=1);

namespace Inverge\Nexus\Telescope;

/**
 * The environment's Telescope settings (paused, sample rate, ignored types and
 * paths, redaction, monitored tags), as last returned by Nexus.
 *
 * PHP-FPM forgets everything between requests, and fetching settings on the
 * request path would add latency, so they are cached in a small file and
 * refreshed from every delivery's response — never fetched synchronously. Until
 * the first delivery the defaults apply (record everything).
 *
 * @internal
 */
final class RemoteConfig
{
    private const TTL = 300;

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    private readonly string $file;

    public function __construct(string $identity, ?string $dir = null)
    {
        $this->file = rtrim($dir ?? sys_get_temp_dir(), '/') . '/nexus-telescope-' . substr(sha1($identity), 0, 16) . '.json';
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $config = [];
        try {
            if (is_file($this->file) && filemtime($this->file) > time() - self::TTL) {
                $decoded = json_decode((string) @file_get_contents($this->file), true);
                $config = \is_array($decoded) ? $decoded : [];
            }
        } catch (\Throwable) {
        }

        return $this->memo = $config;
    }

    /** @param array<string, mixed> $config */
    public function put(array $config): void
    {
        $this->memo = $config;
        try {
            $tmp = $this->file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($config)) !== false) {
                @rename($tmp, $this->file);
            }
        } catch (\Throwable) {
        }
    }
}

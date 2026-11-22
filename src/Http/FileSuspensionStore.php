<?php

declare(strict_types=1);

namespace Inverge\Nexus\Http;

use Inverge\Nexus\Contracts\SuspensionStore;

/**
 * The default {@see SuspensionStore}: one small file per API key in the system
 * temp directory, shared by every PHP process on the host that sees the same
 * temp directory. (Laravel uses its cache instead — php-fpm often runs with a
 * private /tmp, which would hide the file from queue workers.)
 */
final class FileSuspensionStore implements SuspensionStore
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'inverge-nexus';
    }

    public function get(string $key): ?int
    {
        $raw = @file_get_contents($this->path($key));
        if ($raw === false || !ctype_digit(trim($raw))) {
            return null;
        }

        return (int) trim($raw);
    }

    public function put(string $key, int $until): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
        @file_put_contents($this->path($key), (string) $until, LOCK_EX);
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $key . '.suspended';
    }
}

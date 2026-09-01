<?php

declare(strict_types=1);

namespace Inverge\Nexus\Http;

/**
 * A raw HTTP response from a {@see \Inverge\Nexus\Contracts\Transport}.
 */
final class ApiResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decode the JSON body to an associative array (or null when empty).
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        if (trim($this->body) === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}

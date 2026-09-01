<?php

declare(strict_types=1);

namespace Inverge\Nexus;

/**
 * Immutable SDK configuration. Partner endpoints live under `/partner` on the
 * base URL and authenticate with the `x-api-key` header.
 */
final class Config
{
    /**
     * @param array<string, string> $defaultHeaders extra headers sent on every request
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = 'https://api.nexus.inverge.net',
        public readonly float $timeout = 10.0,
        public readonly array $defaultHeaders = [],
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('A Nexus API key is required (nxs_...).');
        }
    }

    /** @param array{base_url?:string,baseUrl?:string,timeout?:float|int,headers?:array<string,string>,default_headers?:array<string,string>} $options */
    public static function fromArray(string $apiKey, array $options = []): self
    {
        return new self(
            apiKey: $apiKey,
            baseUrl: (string) ($options['base_url'] ?? $options['baseUrl'] ?? 'https://api.nexus.inverge.net'),
            timeout: (float) ($options['timeout'] ?? 10.0),
            defaultHeaders: $options['headers'] ?? $options['default_headers'] ?? [],
        );
    }

    /** The base URL without a trailing slash. */
    public function httpBase(): string
    {
        return rtrim($this->baseUrl, '/');
    }
}

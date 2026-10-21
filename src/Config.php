<?php

declare(strict_types=1);

namespace Inverge\Nexus;

/**
 * Immutable SDK configuration. Partner endpoints live under `/partner` on the
 * base URL and authenticate with the `x-api-key` header.
 */
final class Config
{
    private ?Services $fromEnvironment = null;

    /**
     * @param array<string, string> $defaultHeaders extra headers sent on every request
     * @param Services|null         $services       per-service switches; null = read the
     *                                              `NEXUS_*_ENABLED` environment variables
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = 'https://services.inverge.net',
        public readonly float $timeout = 10.0,
        public readonly array $defaultHeaders = [],
        public readonly ?Services $services = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('A Nexus API key is required (nxs_...).');
        }
    }

    /**
     * `services` switches products off, e.g. `['push' => false]` (unset = from the
     * environment); `enabled => false` turns everything off.
     *
     * @param array{base_url?:string,baseUrl?:string,timeout?:float|int,headers?:array<string,string>,default_headers?:array<string,string>,services?:array<string,mixed>|Services,enabled?:bool} $options
     */
    public static function fromArray(string $apiKey, array $options = []): self
    {
        $services = $options['services'] ?? null;
        if (\is_array($services) || \array_key_exists('enabled', $options)) {
            $services = Services::fromArray(\is_array($services) ? $services : [], $options['enabled'] ?? true);
        }

        return new self(
            apiKey: $apiKey,
            baseUrl: (string) ($options['base_url'] ?? $options['baseUrl'] ?? 'https://services.inverge.net'),
            timeout: (float) ($options['timeout'] ?? 10.0),
            defaultHeaders: $options['headers'] ?? $options['default_headers'] ?? [],
            services: $services instanceof Services ? $services : null,
        );
    }

    /** The effective switches: the explicit ones, else the environment's (read once). */
    public function services(): Services
    {
        return $this->services ?? ($this->fromEnvironment ??= Services::fromEnvironment());
    }

    /** The base URL without a trailing slash. */
    public function httpBase(): string
    {
        return rtrim($this->baseUrl, '/');
    }
}

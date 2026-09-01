<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

use Inverge\Nexus\NexusClient;

abstract class AbstractResource
{
    public function __construct(protected readonly NexusClient $client)
    {
    }

    /**
     * Drop null values so optional fields aren't sent (the API validates
     * strictly and some fields reject explicit nulls).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function compact(array $data): array
    {
        return array_filter($data, static fn ($value) => $value !== null);
    }
}

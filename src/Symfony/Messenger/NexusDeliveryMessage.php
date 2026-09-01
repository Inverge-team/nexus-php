<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\Messenger;

/**
 * A queued Nexus request, dispatched on the Symfony message bus when async
 * delivery is enabled. Route it to an async transport in your messenger config.
 */
final class NexusDeliveryMessage
{
    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?array $json = null,
        public readonly array $headers = [],
    ) {
    }
}

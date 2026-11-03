<?php

declare(strict_types=1);

namespace Inverge\Nexus\Contracts;

use Inverge\Nexus\Http\ApiResponse;

/**
 * A transport that can also send a non-JSON body (a file upload).
 *
 * Separate from {@see Transport} so existing custom transports keep working;
 * both built-in transports implement it. Only messaging uploads need it.
 */
interface RawTransport
{
    /** @param array<string, string> $headers */
    public function sendRaw(string $method, string $url, array $headers, string $body, float $timeout): ApiResponse;
}

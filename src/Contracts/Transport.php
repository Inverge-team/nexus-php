<?php

declare(strict_types=1);

namespace Inverge\Nexus\Contracts;

use Inverge\Nexus\Http\ApiResponse;

/**
 * A pluggable HTTP transport. The SDK ships a zero-dependency cURL transport;
 * apps that prefer their own client can inject a PSR-18 one (or any impl).
 */
interface Transport
{
    /**
     * @param array<string, string>      $headers
     * @param array<string, mixed>|null  $json    JSON body, or null for no body
     */
    public function send(string $method, string $url, array $headers, ?array $json, float $timeout): ApiResponse;
}

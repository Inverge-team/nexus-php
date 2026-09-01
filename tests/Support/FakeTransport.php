<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests\Support;

use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Http\ApiResponse;

/** In-memory transport: records requests, returns queued responses. */
final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,json:array<string,mixed>|null}> */
    public array $requests = [];

    /** @var list<ApiResponse> */
    private array $queue;

    public function __construct(ApiResponse ...$responses)
    {
        $this->queue = $responses;
    }

    public function send(string $method, string $url, array $headers, ?array $json, float $timeout): ApiResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'json' => $json,
        ];

        return array_shift($this->queue) ?? new ApiResponse(200, '{}');
    }

    /** @return array{method:string,url:string,headers:array<string,string>,json:array<string,mixed>|null} */
    public function lastRequest(): array
    {
        if ($this->requests === []) {
            throw new \LogicException('No requests recorded.');
        }

        return $this->requests[array_key_last($this->requests)];
    }
}

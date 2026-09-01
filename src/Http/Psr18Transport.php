<?php

declare(strict_types=1);

namespace Inverge\Nexus\Http;

use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Adapter for any PSR-18 HTTP client (Guzzle, Symfony HttpClient's PSR-18
 * adapter, etc.). Timeouts are governed by the injected client's own config.
 *
 * Requires psr/http-client + psr/http-factory (dev-suggested, not a hard dep).
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(string $method, string $url, array $headers, ?array $json, float $timeout): ApiResponse
    {
        $request = $this->requestFactory->createRequest(strtoupper($method), $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($json !== null) {
            $encoded = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new TransportException('Failed to encode the request body as JSON: ' . json_last_error_msg());
            }
            $request = $request->withBody($this->streamFactory->createStream($encoded));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException("HTTP {$method} {$url} failed: {$e->getMessage()}", 0, $e);
        }

        return new ApiResponse($response->getStatusCode(), (string) $response->getBody());
    }
}

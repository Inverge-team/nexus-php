<?php

declare(strict_types=1);

namespace Inverge\Nexus\Http;

use Inverge\Nexus\Config;
use Inverge\Nexus\Contracts\Dispatcher;
use Inverge\Nexus\Contracts\RawTransport;
use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\NexusException;

/**
 * The default dispatcher — authenticates and sends the request synchronously
 * over a {@see Transport}, throwing {@see ApiException} on a non-2xx response.
 */
final class SyncDispatcher implements Dispatcher
{
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport = new CurlTransport(),
    ) {
    }

    public function dispatch(string $method, string $path, ?array $json = null, array $headers = []): ?array
    {
        $allHeaders = $this->headers($headers);
        if ($json !== null) {
            $allHeaders['Content-Type'] = 'application/json';
        }

        $response = $this->transport->send($method, $this->config->httpBase() . $path, $allHeaders, $json, $this->config->timeout);

        if (!$response->successful()) {
            throw ApiException::fromResponse($response);
        }

        return $response->json();
    }

    /**
     * Send a raw (non-JSON) body — a file upload.
     *
     * @param array<string, string> $headers
     *
     * @return array<mixed>|null
     */
    public function dispatchRaw(string $method, string $path, string $body, string $contentType, array $headers = []): ?array
    {
        if (!$this->transport instanceof RawTransport) {
            throw new NexusException(
                'This transport cannot upload files. Implement ' . RawTransport::class
                . ' on it, or use the built-in cURL / PSR-18 transport.',
            );
        }
        $allHeaders = $this->headers($headers);
        $allHeaders['Content-Type'] = $contentType;

        $response = $this->transport->sendRaw($method, $this->config->httpBase() . $path, $allHeaders, $body, $this->config->timeout);

        if (!$response->successful()) {
            throw ApiException::fromResponse($response);
        }

        return $response->json();
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        return array_merge(
            [
                'x-api-key' => $this->config->apiKey,
                'Accept' => 'application/json',
            ],
            $this->config->defaultHeaders,
            $headers,
        );
    }
}

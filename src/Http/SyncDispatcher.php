<?php

declare(strict_types=1);

namespace Inverge\Nexus\Http;

use Inverge\Nexus\Config;
use Inverge\Nexus\Contracts\Dispatcher;
use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Exception\ApiException;

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
        $url = $this->config->httpBase() . $path;

        $allHeaders = array_merge(
            [
                'x-api-key' => $this->config->apiKey,
                'Accept' => 'application/json',
            ],
            $this->config->defaultHeaders,
            $headers,
        );

        if ($json !== null) {
            $allHeaders['Content-Type'] = 'application/json';
        }

        $response = $this->transport->send($method, $url, $allHeaders, $json, $this->config->timeout);

        if (!$response->successful()) {
            throw ApiException::fromResponse($response);
        }

        return $response->json();
    }
}

<?php

declare(strict_types=1);

namespace Inverge\Nexus\Http;

use Inverge\Nexus\Config;
use Inverge\Nexus\Contracts\Dispatcher;
use Inverge\Nexus\Contracts\RawTransport;
use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\BillingSuspendedException;
use Inverge\Nexus\Exception\NexusException;

/**
 * The default dispatcher — authenticates and sends the request synchronously
 * over a {@see Transport}, throwing {@see ApiException} on a non-2xx response.
 *
 * Every request passes the {@see BillingSuspension} breaker: after a 402 the
 * dispatcher refuses locally ({@see BillingSuspendedException}) instead of
 * calling a suspended API.
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
        $this->guard($path);
        $allHeaders = $this->headers($headers);
        if ($json !== null) {
            $allHeaders['Content-Type'] = 'application/json';
        }

        $response = $this->transport->send($method, $this->config->httpBase() . $path, $allHeaders, $json, $this->config->timeout);

        return $this->result($path, $response);
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
        $this->guard($path);
        $allHeaders = $this->headers($headers);
        $allHeaders['Content-Type'] = $contentType;

        $response = $this->transport->sendRaw($method, $this->config->httpBase() . $path, $allHeaders, $body, $this->config->timeout);

        return $this->result($path, $response);
    }

    /** Refuse locally — no request — while billing access is suspended. */
    private function guard(string $path): void
    {
        $refused = BillingSuspension::check($this->config, $path);
        if ($refused !== null) {
            throw $refused;
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function result(string $path, ApiResponse $response): ?array
    {
        if ($response->successful()) {
            BillingSuspension::record($this->config, $path, $response->status);

            return $response->json();
        }

        $error = ApiException::fromResponse($response);
        $seconds = BillingSuspension::record($this->config, $path, $error->status, $error->errorCode, $error->details);
        if ($seconds !== null) {
            throw new BillingSuspendedException($error->getMessage(), $seconds, false, $error->details);
        }

        throw $error;
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

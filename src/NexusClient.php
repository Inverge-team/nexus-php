<?php

declare(strict_types=1);

namespace Inverge\Nexus;

use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Http\CurlTransport;
use Inverge\Nexus\Resource\Errors;
use Inverge\Nexus\Resource\Events;
use Inverge\Nexus\Resource\Flags;
use Inverge\Nexus\Resource\Links;
use Inverge\Nexus\Resource\Logs;
use Inverge\Nexus\Resource\Realtime;
use Inverge\Nexus\Resource\Sessions;

/**
 * The Nexus server-side SDK. Framework-agnostic: construct once with an API key
 * and use any resource.
 *
 * ```php
 * $nexus = NexusClient::create('nxs_live_...');
 * $nexus->realtime()->emit('orders:42', 'status', ['state' => 'shipped']);
 * $nexus->events()->capture('order_placed', ['total' => 42.0], ['distinctId' => 'u_1']);
 * $flags = $nexus->flags()->evaluate('u_1');
 * ```
 */
final class NexusClient
{
    private Realtime $realtime;
    private Events $events;
    private Errors $errors;
    private Logs $logs;
    private Sessions $sessions;
    private Flags $flags;
    private Links $links;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport = new CurlTransport(),
    ) {
        $this->realtime = new Realtime($this);
        $this->events = new Events($this);
        $this->errors = new Errors($this);
        $this->logs = new Logs($this);
        $this->sessions = new Sessions($this);
        $this->flags = new Flags($this);
        $this->links = new Links($this);
    }

    /**
     * Convenience factory using the built-in cURL transport.
     *
     * @param array{base_url?:string,timeout?:float|int,headers?:array<string,string>} $options
     */
    public static function create(string $apiKey, array $options = []): self
    {
        return new self(Config::fromArray($apiKey, $options));
    }

    public function realtime(): Realtime
    {
        return $this->realtime;
    }

    public function events(): Events
    {
        return $this->events;
    }

    public function errors(): Errors
    {
        return $this->errors;
    }

    public function logs(): Logs
    {
        return $this->logs;
    }

    public function sessions(): Sessions
    {
        return $this->sessions;
    }

    public function flags(): Flags
    {
        return $this->flags;
    }

    public function links(): Links
    {
        return $this->links;
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Perform an authenticated partner-API request and return the decoded body.
     *
     * @internal used by resource classes
     *
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     *
     * @return array<mixed>|null
     *
     * @throws ApiException on a non-2xx response
     */
    public function request(string $method, string $path, ?array $json = null, array $headers = []): ?array
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

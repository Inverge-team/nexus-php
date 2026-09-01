<?php

declare(strict_types=1);

namespace Inverge\Nexus;

use Inverge\Nexus\Contracts\Dispatcher;
use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Http\CurlTransport;
use Inverge\Nexus\Http\SyncDispatcher;
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
    private readonly Dispatcher $dispatcher;

    private Realtime $realtime;
    private Events $events;
    private Errors $errors;
    private Logs $logs;
    private Sessions $sessions;
    private Flags $flags;
    private Links $links;

    /**
     * @param Transport|null  $transport   transport for the default sync dispatcher (ignored if $dispatcher is given)
     * @param Dispatcher|null $dispatcher  a custom dispatcher (e.g. a queued one); defaults to synchronous cURL
     */
    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
        ?Dispatcher $dispatcher = null,
    ) {
        $this->dispatcher = $dispatcher ?? new SyncDispatcher($config, $transport ?? new CurlTransport());

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

    /**
     * A clone that delivers through a different dispatcher — e.g. a queued one
     * for fire-and-forget telemetry. Result-returning calls (flags, sessions)
     * shouldn't be used on a deferred dispatcher since they can't return.
     */
    public function withDispatcher(Dispatcher $dispatcher): self
    {
        return new self($this->config, null, $dispatcher);
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
        return $this->dispatcher->dispatch($method, $path, $json, $headers);
    }
}

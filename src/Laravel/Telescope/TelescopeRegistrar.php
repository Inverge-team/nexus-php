<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Telescope;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inverge\Nexus\Telescope\Telescope;
use Inverge\Nexus\Telescope\TraceContext;
use Psr\Http\Message\RequestInterface;

/**
 * Wires Nexus Telescope into a Laravel app: one listener per watcher, all
 * best-effort — a watcher that fails is skipped, it never breaks the app.
 *
 * Every watcher can be switched off in `config/nexus.php` → `telescope.watchers`,
 * and every entry type can also be switched off from the Nexus console without
 * a deploy.
 *
 * @internal
 */
final class TelescopeRegistrar
{
    /** Events that are framework plumbing, not the app's own. */
    private const IGNORED_EVENT_PREFIXES = [
        'Illuminate\\', 'Laravel\\', 'Inverge\\', 'eloquent.', 'bootstrapped:', 'bootstrapping:', 'creating:',
        'composing:', 'illuminate.', 'kernel.', 'Livewire\\', 'Symfony\\',
    ];

    private float $routeMatchedAt = 0.0;
    private float $controllerEndedAt = 0.0;

    /** @var array<string, float> outgoing HTTP span id → start */
    private array $httpStarts = [];

    /** The span id generated for the job being queued right now. */
    private ?string $queuedSpan = null;

    /** @var array<int, string> spl_object_id(job) → batch id */
    private array $jobBatches = [];

    /** @param array<string, mixed> $config the `nexus.telescope` config */
    public function __construct(
        private readonly Application $app,
        private readonly Telescope $telescope,
        private readonly array $config,
    ) {
    }

    public function register(): void
    {
        $w = (array) ($this->config['watchers'] ?? []);
        $on = static fn (string $name): bool => (bool) ($w[$name] ?? true);
        $events = $this->app->make(Dispatcher::class);

        $steps = [
            'requests' => fn () => $this->requests($events),
            'queries' => fn () => $this->queries($events),
            'models' => fn () => $this->models($events),
            'events' => fn () => $this->events($events),
            'jobs' => fn () => $this->jobs($events),
            'commands' => fn () => $this->commands($events),
            'schedule' => fn () => $this->schedule($events),
            'exceptions' => fn () => $this->exceptions(),
            'logs' => fn () => $this->logs($events),
            'http' => fn () => $this->http($events),
            'cache' => fn () => $this->cache($events),
            'redis' => fn () => $this->redis($events),
            'mail' => fn () => $this->mail($events),
            'notifications' => fn () => $this->notifications($events),
            'gates' => fn () => $this->gates(),
            'views' => fn () => $this->views($events),
            'dumps' => fn () => $this->dumps(),
            'batches' => fn () => $this->batches($events),
        ];
        foreach ($steps as $name => $step) {
            if (!$on($name)) {
                continue;
            }
            try {
                $step();
            } catch (\Throwable) {
                // a watcher that cannot attach (missing component, old Laravel) is skipped
            }
        }
    }

    public function octane(): bool
    {
        return isset($_SERVER['LARAVEL_OCTANE']) || isset($_ENV['LARAVEL_OCTANE']);
    }

    // ── requests & middleware phases ────────────────────────────────────────

    private function requests(Dispatcher $events): void
    {
        if ($this->app->runningInConsole() && !$this->octane()) {
            return;
        }
        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(RecordRequest::class);
        }
        $this->app->singleton(RecordRequest::class, fn () => new RecordRequest($this->telescope, $this));

        if ($this->on('middleware')) {
            $this->app->extend(ControllerDispatcher::class, fn (ControllerDispatcher $d) => new TimedControllerDispatcher($d, $this->telescope, $this));

            $events->listen('Illuminate\Routing\Events\RouteMatched', function ($event): void {
                if (!$this->telescope->active() || $this->telescope->current()?->type !== 'request') {
                    return;
                }
                $now = microtime(true);
                $this->telescope->record('middleware', ['name' => 'Global middleware & routing'], [
                    'name' => 'Global middleware & routing',
                    'startedAt' => $this->requestStartedAt,
                    'durationMs' => ($now - $this->requestStartedAt) * 1000,
                ]);
                $this->routeMatchedAt = $now;
            });
            $events->listen('Illuminate\Foundation\Http\Events\RequestHandled', function (): void {
                if ($this->controllerEndedAt > 0 && $this->telescope->current()?->type === 'request') {
                    $this->telescope->record('middleware', ['name' => 'Response through middleware'], [
                        'name' => 'Response through middleware',
                        'startedAt' => $this->controllerEndedAt,
                        'durationMs' => (microtime(true) - $this->controllerEndedAt) * 1000,
                    ]);
                }
                $this->controllerEndedAt = 0.0;
                $this->routeMatchedAt = 0.0;
            });
        }
    }

    private float $requestStartedAt = 0.0;

    /** @internal called by RecordRequest */
    public function requestStarted(float $at): void
    {
        $this->requestStartedAt = $at;
        $this->routeMatchedAt = 0.0;
        $this->controllerEndedAt = 0.0;
    }

    /** @internal called by TimedControllerDispatcher */
    public function controllerStarting(): void
    {
        if ($this->routeMatchedAt > 0) {
            $route = $this->app['request']->route();
            $names = \is_object($route) ? implode(', ', array_map('strval', $route->gatherMiddleware())) : '';
            $label = 'Route middleware' . ($names !== '' ? ": {$names}" : '');
            $this->telescope->record('middleware', ['name' => $label, 'middleware' => $names], [
                'name' => mb_substr($label, 0, 300),
                'startedAt' => $this->routeMatchedAt,
                'durationMs' => (microtime(true) - $this->routeMatchedAt) * 1000,
            ]);
        }
    }

    /** @internal */
    public function controllerFinished(): void
    {
        $this->controllerEndedAt = microtime(true);
    }

    // ── database ────────────────────────────────────────────────────────────

    private function queries(Dispatcher $events): void
    {
        $events->listen('Illuminate\Database\Events\QueryExecuted', function ($event): void {
            if (!$this->telescope->active()) {
                return;
            }
            $extra = $this->location();
            $this->telescope->query((string) $event->sql, (array) $event->bindings, (float) $event->time, (string) $event->connectionName, $extra);
        });
    }

    private function models(Dispatcher $events): void
    {
        $events->listen('eloquent.*', function (string $name, array $payload): void {
            if (!$this->telescope->active()) {
                return;
            }
            $action = substr($name, 9, (int) strpos($name, ':') - 9);
            $model = $payload[0] ?? null;
            if (!\is_object($model) || !method_exists($model, 'getKey')) {
                return;
            }
            if ($action === 'retrieved') {
                // Hydrations are counted, not listed — a page can load thousands.
                $batch = $this->telescope->current();
                if ($batch !== null) {
                    $batch->content['modelsRetrieved'][$model::class] = ($batch->content['modelsRetrieved'][$model::class] ?? 0) + 1;
                }

                return;
            }
            if (!\in_array($action, ['created', 'updated', 'deleted', 'restored', 'forceDeleted'], true)) {
                return;
            }
            $changes = $action === 'updated' && method_exists($model, 'getChanges') ? $model->getChanges() : ($action === 'created' && method_exists($model, 'getAttributes') ? $model->getAttributes() : []);
            $this->telescope->record('model', [
                'action' => $action,
                'model' => $model::class,
                'key' => Telescope::scalarize($model->getKey()),
                'changes' => Telescope::scalarize($changes),
            ], ['status' => $action]);
        });
    }

    // ── events ──────────────────────────────────────────────────────────────

    private function events(Dispatcher $events): void
    {
        $events->listen('*', function (string $name, array $payload) use ($events): void {
            if (!$this->telescope->active()) {
                return;
            }
            foreach (self::IGNORED_EVENT_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return;
                }
            }
            $event = $payload[0] ?? null;
            $this->telescope->record('event', [
                'name' => $name,
                'payload' => \is_object($event) ? Telescope::scalarize(get_object_vars($event)) : Telescope::scalarize($payload),
                'listeners' => $this->listenersFor($events, $name),
                'broadcast' => \is_object($event) && interface_exists('Illuminate\Contracts\Broadcasting\ShouldBroadcast') && $event instanceof \Illuminate\Contracts\Broadcasting\ShouldBroadcast,
            ]);
        });
    }

    /** @return list<string> */
    private function listenersFor(Dispatcher $events, string $name): array
    {
        if (!method_exists($events, 'getRawListeners')) {
            return [];
        }
        $out = [];
        foreach ((array) ($events->getRawListeners()[$name] ?? []) as $l) {
            if (\is_string($l)) {
                $out[] = $l;
            } elseif (\is_array($l) && isset($l[0])) {
                $out[] = (\is_object($l[0]) ? $l[0]::class : (string) $l[0]) . '@' . ($l[1] ?? 'handle');
            } elseif ($l instanceof \Closure) {
                $r = new \ReflectionFunction($l);
                $out[] = 'Closure at ' . $r->getFileName() . ':' . $r->getStartLine();
            }
        }

        return $out;
    }

    // ── queue ───────────────────────────────────────────────────────────────

    private function jobs(Dispatcher $events): void
    {
        // Every queued payload carries the trace, so the job's execution —
        // on any worker, any server — joins the journey that dispatched it.
        Queue::createPayloadUsing(function ($connection, $queue, $payload): array {
            $this->queuedSpan = null;
            if (!$this->telescope->active() || str_starts_with((string) ($payload['displayName'] ?? ''), 'Inverge\\Nexus\\')) {
                return [];
            }
            $this->queuedSpan = TraceContext::newSpanId();

            return ['nexusTelescope' => $this->telescope->propagation($this->queuedSpan)];
        });

        $events->listen('Illuminate\Queue\Events\JobQueued', function ($event): void {
            $span = $this->queuedSpan;
            $this->queuedSpan = null;
            if ($span === null || !$this->telescope->active()) {
                return;
            }
            $job = $event->job ?? null;
            $this->telescope->record('job', [
                'name' => \is_object($job) ? $job::class : (\is_string($job) ? $job : 'Closure'),
                'connection' => $event->connectionName ?? null,
                'queue' => $event->queue ?? (\is_object($job) && property_exists($job, 'queue') ? $job->queue : null),
                'jobId' => $event->id ?? null,
                'status' => 'dispatched',
                'data' => \is_object($job) ? Telescope::scalarize(get_object_vars($job)) : null,
            ], ['id' => $span, 'status' => 'dispatched']);
        });

        $events->listen('Illuminate\Queue\Events\JobProcessing', function ($event): void {
            $job = $event->job;
            $payload = (array) $job->payload();
            $name = method_exists($job, 'resolveName') ? $job->resolveName() : (string) ($payload['displayName'] ?? 'job');
            if (str_starts_with($name, 'Inverge\\Nexus\\')) {
                return;
            }
            $trace = (array) ($payload['nexusTelescope'] ?? []);
            $id = $this->telescope->start('job', [
                'name' => $name,
                'connection' => $event->connectionName,
                'queue' => method_exists($job, 'getQueue') ? $job->getQueue() : null,
                'jobId' => method_exists($job, 'getJobId') ? $job->getJobId() : null,
                'attempts' => method_exists($job, 'attempts') ? $job->attempts() : null,
                'data' => Telescope::scalarize($this->jobData($payload)),
            ], array_filter([
                'traceId' => $trace['traceId'] ?? null,
                'parentSpanId' => $trace['parentSpanId'] ?? null,
                'name' => $name,
            ]));
            if ($id !== null) {
                $this->jobBatches[spl_object_id($job)] = $id;
            }
        });

        $events->listen('Illuminate\Queue\Events\JobProcessed', function ($event): void {
            $this->finishJob($event->job, 'processed');
        });

        $events->listen('Illuminate\Queue\Events\JobFailed', function ($event): void {
            if ($this->ownsJob($event->job) && isset($event->exception)) {
                $this->telescope->exception($event->exception, false, ['job' => $this->telescope->current()?->name]);
            }
        });

        // Fires after the worker has decided fail-or-release, so the status is final.
        $events->listen('Illuminate\Queue\Events\JobExceptionOccurred', function ($event): void {
            if (!$this->ownsJob($event->job)) {
                return;
            }
            $failed = method_exists($event->job, 'hasFailed') && $event->job->hasFailed();
            if (!$failed && isset($event->exception)) {
                $this->telescope->exception($event->exception, false, ['job' => $this->telescope->current()?->name, 'released' => true]);
            }
            $this->finishJob($event->job, $failed ? 'failed' : 'released', $event->exception ?? null);
        });
    }

    private function ownsJob(object $job): bool
    {
        $id = $this->jobBatches[spl_object_id($job)] ?? null;

        return $id !== null && $this->telescope->current()?->id === $id;
    }

    private function finishJob(object $job, string $status, ?\Throwable $e = null): void
    {
        if (!$this->ownsJob($job)) {
            return;
        }
        unset($this->jobBatches[spl_object_id($job)]);
        $this->telescope->finish(array_filter(['status' => $status, 'error' => $e?->getMessage()]), $status);
    }

    /** @param array<string, mixed> $payload @return mixed */
    private function jobData(array $payload)
    {
        $command = $payload['data']['command'] ?? null;
        if (\is_string($command)) {
            try {
                $obj = @unserialize($command, ['allowed_classes' => true]);
                if (\is_object($obj)) {
                    return get_object_vars($obj);
                }
            } catch (\Throwable) {
            }
        }

        return $payload['data'] ?? null;
    }

    // ── console ─────────────────────────────────────────────────────────────

    private function commands(Dispatcher $events): void
    {
        $ignored = (array) ($this->config['ignore_commands'] ?? []);
        $events->listen('Illuminate\Console\Events\CommandStarting', function ($event) use ($ignored): void {
            $command = (string) ($event->command ?? '');
            if ($command === '' || $this->matchesAny($command, $ignored)) {
                return;
            }
            $this->telescope->start('command', [
                'command' => $command,
                'arguments' => Telescope::scalarize($event->input->getArguments()),
                'options' => Telescope::scalarize(array_filter($event->input->getOptions(), static fn ($v) => $v !== null && $v !== false && $v !== [])),
            ], array_filter(['traceparent' => getenv('TRACEPARENT') ?: null]));
        });
        $events->listen('Illuminate\Console\Events\CommandFinished', function ($event) use ($ignored): void {
            $command = (string) ($event->command ?? '');
            if ($command === '' || $this->matchesAny($command, $ignored) || $this->telescope->current()?->type !== 'command') {
                return;
            }
            $this->telescope->finish(['exitCode' => $event->exitCode], (string) $event->exitCode);
        });
    }

    private function schedule(Dispatcher $events): void
    {
        $events->listen('Illuminate\Console\Events\ScheduledTaskStarting', function ($event): void {
            $task = $event->task;
            $this->telescope->start('schedule', [
                'command' => $task->command ?? null,
                'description' => $task->description ?? null,
                'expression' => $task->expression ?? null,
                'timezone' => \is_string($task->timezone ?? null) ? $task->timezone : null,
                'user' => $task->user ?? null,
            ]);
        });
        $events->listen('Illuminate\Console\Events\ScheduledTaskFinished', function ($event): void {
            if ($this->telescope->current()?->type === 'schedule') {
                $code = $event->task->exitCode ?? 0;
                $this->telescope->finish(['exitCode' => $code, 'runtime' => $event->runtime ?? null], (string) ($code ?? 0));
            }
        });
        $events->listen('Illuminate\Console\Events\ScheduledTaskFailed', function ($event): void {
            if ($this->telescope->current()?->type === 'schedule') {
                $this->telescope->exception($event->exception);
                $this->telescope->finish(['error' => $event->exception->getMessage()], 'failed');
            }
        });
    }

    // ── problems ────────────────────────────────────────────────────────────

    private function exceptions(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(function (\Throwable $e): void {
                if ($this->telescope->active()) {
                    $this->telescope->exception($e);
                }
            });
        }
    }

    private function logs(Dispatcher $events): void
    {
        $events->listen('Illuminate\Log\Events\MessageLogged', function ($event): void {
            if (!$this->telescope->active() || (($event->context['exception'] ?? null) instanceof \Throwable)) {
                return; // an exception log is already an exception entry
            }
            $this->telescope->log((string) $event->level, (string) $event->message, (array) $event->context);
        });
    }

    // ── outgoing ────────────────────────────────────────────────────────────

    private function http(Dispatcher $events): void
    {
        // Propagate the trace to the services we call (W3C traceparent), and
        // remember when each call started.
        if (class_exists(Http::class) && method_exists(Http::getFacadeRoot(), 'globalRequestMiddleware')) {
            Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
                if (!$this->telescope->active()) {
                    return $request;
                }
                $span = TraceContext::newSpanId();
                $this->httpStarts[$span] = microtime(true);
                $tp = $this->telescope->traceparent($span);

                return $tp !== null && !$request->hasHeader('traceparent') ? $request->withHeader('traceparent', $tp) : $request;
            });
        }

        $events->listen('Illuminate\Http\Client\Events\ResponseReceived', function ($event): void {
            if (!$this->telescope->active()) {
                return;
            }
            [$span, $startedAt] = $this->httpSpan($event->request);
            $response = $event->response;
            $durationMs = $startedAt !== null ? (microtime(true) - $startedAt) * 1000 : null;
            if ($durationMs === null && method_exists($response, 'transferStats') && $response->transferStats) {
                $durationMs = $response->transferStats->getTransferTime() * 1000;
            }
            $this->telescope->record('http', [
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'requestHeaders' => RecordRequest::headers((array) $event->request->headers()),
                'payload' => Telescope::scalarize($this->requestBody($event->request)),
                'responseStatus' => $response->status(),
                'responseHeaders' => RecordRequest::headers((array) $response->headers()),
                'response' => $this->responseBody($response),
            ], array_filter(['id' => $span, 'durationMs' => $durationMs, 'startedAt' => $startedAt, 'status' => (string) $response->status()], static fn ($v) => $v !== null));
        });

        $events->listen('Illuminate\Http\Client\Events\ConnectionFailed', function ($event): void {
            if (!$this->telescope->active()) {
                return;
            }
            [$span, $startedAt] = $this->httpSpan($event->request);
            $this->telescope->record('http', [
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'error' => isset($event->exception) ? $event->exception->getMessage() : 'Connection failed',
            ], array_filter(['id' => $span, 'startedAt' => $startedAt, 'durationMs' => $startedAt !== null ? (microtime(true) - $startedAt) * 1000 : null, 'status' => 'failed'], static fn ($v) => $v !== null));
        });
    }

    /** @return array{0:?string,1:?float} */
    private function httpSpan(object $request): array
    {
        $tp = TraceContext::parse((string) ($request->header('traceparent')[0] ?? ''));
        $span = $tp['spanId'] ?? null;
        $startedAt = $span !== null ? ($this->httpStarts[$span] ?? null) : null;
        if ($span !== null) {
            unset($this->httpStarts[$span]);
        }

        return [$startedAt !== null ? $span : null, $startedAt];
    }

    /** @return mixed */
    private function requestBody(object $request)
    {
        try {
            if (method_exists($request, 'isJson') && $request->isJson()) {
                return $request->data();
            }
            if (method_exists($request, 'isForm') && $request->isForm()) {
                return $request->data();
            }
            $body = (string) $request->body();

            return \strlen($body) > 4096 ? mb_substr($body, 0, 4096) . '…' : $body;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return mixed */
    private function responseBody(object $response)
    {
        try {
            $body = (string) $response->body();
            if (\strlen($body) > (int) $this->telescope->option('body_size_limit') * 1024) {
                return 'Response too large — not recorded';
            }
            $json = json_decode($body, true);

            return \is_array($json) ? $json : (mb_check_encoding($body, 'UTF-8') ? $body : 'Binary response');
        } catch (\Throwable) {
            return null;
        }
    }

    private function cache(Dispatcher $events): void
    {
        $skip = static fn (string $key): bool => str_starts_with($key, 'illuminate:') || str_starts_with($key, 'telescope:') || str_starts_with($key, 'laravel_') || str_starts_with($key, 'nexus');
        $map = [
            'Illuminate\Cache\Events\CacheHit' => 'hit',
            'Illuminate\Cache\Events\CacheMissed' => 'missed',
            'Illuminate\Cache\Events\KeyWritten' => 'set',
            'Illuminate\Cache\Events\KeyForgotten' => 'forget',
        ];
        foreach ($map as $class => $action) {
            $events->listen($class, function ($event) use ($action, $skip): void {
                if (!$this->telescope->active() || $skip((string) $event->key)) {
                    return;
                }
                $this->telescope->record('cache', array_filter([
                    'action' => $action,
                    'key' => (string) $event->key,
                    'store' => $event->storeName ?? null,
                    'expiration' => $event->seconds ?? null,
                    'value' => $action === 'hit' || $action === 'set' ? Telescope::scalarize($event->value ?? null) : null,
                    'tags' => $event->tags ?? null,
                ], static fn ($v) => $v !== null && $v !== []), ['status' => $action]);
            });
        }
    }

    private function redis(Dispatcher $events): void
    {
        if (!$this->app->bound('redis')) {
            return;
        }
        $events->listen('Illuminate\Redis\Events\CommandExecuted', function ($event): void {
            if (!$this->telescope->active() || \in_array(strtolower((string) $event->command), ['pipeline', 'transaction'], true)) {
                return;
            }
            $args = array_map(static fn ($p) => \is_scalar($p) ? (string) $p : json_encode(Telescope::scalarize($p)), (array) $event->parameters);
            $this->telescope->record('redis', [
                'command' => trim(strtoupper((string) $event->command) . ' ' . mb_substr(implode(' ', $args), 0, 500)),
                'connection' => $event->connectionName,
            ], ['durationMs' => (float) $event->time]);
        });
        $this->app->make('redis')->enableEvents();
    }

    private function mail(Dispatcher $events): void
    {
        $events->listen('Illuminate\Mail\Events\MessageSent', function ($event): void {
            if (!$this->telescope->active()) {
                return;
            }
            $m = $event->message;
            $addr = static fn ($list) => implode(', ', array_map(static fn ($a) => method_exists($a, 'toString') ? $a->toString() : (string) $a, (array) $list));
            $html = method_exists($m, 'getHtmlBody') ? $m->getHtmlBody() : null;
            $this->telescope->record('mail', array_filter([
                'mailable' => isset($event->data['__laravel_notification']) ? $event->data['__laravel_notification'] : null,
                'subject' => method_exists($m, 'getSubject') ? $m->getSubject() : null,
                'from' => method_exists($m, 'getFrom') ? $addr($m->getFrom()) : null,
                'to' => method_exists($m, 'getTo') ? $addr($m->getTo()) : null,
                'cc' => method_exists($m, 'getCc') ? $addr($m->getCc()) : null,
                'bcc' => method_exists($m, 'getBcc') ? $addr($m->getBcc()) : null,
                'html' => \is_string($html) && \strlen($html) <= 256 * 1024 ? $html : null,
                'text' => method_exists($m, 'getTextBody') && \is_string($m->getTextBody()) ? mb_substr($m->getTextBody(), 0, 8000) : null,
            ], static fn ($v) => $v !== null && $v !== ''));
        });
    }

    private function notifications(Dispatcher $events): void
    {
        $events->listen('Illuminate\Notifications\Events\NotificationSent', function ($event): void {
            if (!$this->telescope->active()) {
                return;
            }
            $notifiable = $event->notifiable;
            $this->telescope->record('notification', [
                'notification' => $event->notification::class,
                'channel' => \is_string($event->channel) ? $event->channel : (\is_object($event->channel) ? $event->channel::class : null),
                'notifiable' => \is_object($notifiable) ? Telescope::scalarize($notifiable) : (string) $notifiable,
                'queued' => interface_exists('Illuminate\Contracts\Queue\ShouldQueue') && $event->notification instanceof \Illuminate\Contracts\Queue\ShouldQueue,
            ]);
        });
    }

    private function gates(): void
    {
        // `$user = null` so Laravel also calls this for guests.
        $this->app->make(Gate::class)->after(function ($user = null, string $ability = '', $result = null, $arguments = []): void {
            if (!$this->telescope->active()) {
                return;
            }
            $this->telescope->record('gate', [
                'ability' => $ability,
                'result' => $result ? 'allowed' : 'denied',
                'user' => \is_object($user) && method_exists($user, 'getAuthIdentifier') ? (string) $user->getAuthIdentifier() : null,
                'arguments' => Telescope::scalarize($arguments),
            ] + $this->location(), ['status' => $result ? 'allowed' : 'denied']);
        });
    }

    private function views(Dispatcher $events): void
    {
        $events->listen('composing:*', function (string $name, array $payload): void {
            $view = $payload[0] ?? null;
            if (!$this->telescope->active() || !\is_object($view) || !method_exists($view, 'getName')) {
                return;
            }
            // The framework's own error page renders ~80 component views.
            $namespace = strstr((string) $view->getName(), '::', true);
            if ($namespace !== false && \in_array($namespace, ['laravel-exceptions-renderer', 'laravel-exceptions', 'telescope', 'horizon', 'pulse'], true)) {
                return;
            }
            $this->telescope->record('view', [
                'name' => $view->getName(),
                'path' => method_exists($view, 'getPath') ? str_replace(base_path() . '/', '', (string) $view->getPath()) : null,
                'data' => array_keys(method_exists($view, 'getData') ? (array) $view->getData() : []),
            ]);
        });
    }

    private function dumps(): void
    {
        if (!class_exists('Symfony\Component\VarDumper\VarDumper') || !class_exists('Symfony\Component\VarDumper\Cloner\VarCloner')) {
            return;
        }
        $previous = null;
        $handler = function (...$args) use (&$previous, &$handler): void {
            $var = $args[0] ?? null;
            if ($this->telescope->active()) {
                try {
                    $dumper = new \Symfony\Component\VarDumper\Dumper\CliDumper();
                    $dumper->setColors(false);
                    $text = $dumper->dump((new \Symfony\Component\VarDumper\Cloner\VarCloner())->cloneVar($var), true);
                    $this->telescope->record('dump', ['dump' => mb_substr((string) $text, 0, 20000)] + $this->location());
                } catch (\Throwable) {
                }
            }
            // Hand the dump back to whatever would have handled it.
            \Symfony\Component\VarDumper\VarDumper::setHandler($previous);
            try {
                \Symfony\Component\VarDumper\VarDumper::dump(...$args);
            } finally {
                $previous = \Symfony\Component\VarDumper\VarDumper::setHandler($handler);
            }
        };
        $previous = \Symfony\Component\VarDumper\VarDumper::setHandler($handler);
    }

    private function batches(Dispatcher $events): void
    {
        $events->listen('Illuminate\Bus\Events\BatchDispatched', function ($event): void {
            if (!$this->telescope->active()) {
                return;
            }
            $b = $event->batch;
            $this->telescope->record('batch', [
                'id' => $b->id,
                'name' => $b->name ?: 'Batch ' . $b->id,
                'totalJobs' => $b->totalJobs,
                'pendingJobs' => $b->pendingJobs,
                'failedJobs' => $b->failedJobs,
                'allowFailures' => method_exists($b, 'allowsFailures') ? $b->allowsFailures() : null,
            ]);
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function on(string $watcher): bool
    {
        return (bool) (((array) ($this->config['watchers'] ?? []))[$watcher] ?? true);
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (\Inverge\Nexus\Telescope\Redactor::globMatch((string) $p, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The app frame that caused an entry (first frame outside vendor/ and this
     * SDK) — what turns "a slow query" into "a slow query at OrderController:31".
     *
     * @return array{file?:string,line?:int}
     */
    private function location(): array
    {
        if (!($this->config['locations'] ?? true)) {
            return [];
        }
        $base = base_path() . '/';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || str_contains($file, '/vendor/') || str_contains($file, '/nexus-php/src/')) {
                continue;
            }

            return ['file' => str_replace($base, '', $file), 'line' => (int) ($frame['line'] ?? 0)];
        }

        return [];
    }
}

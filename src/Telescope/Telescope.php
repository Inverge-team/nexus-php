<?php

declare(strict_types=1);

namespace Inverge\Nexus\Telescope;

use Inverge\Nexus\Config;
use Inverge\Nexus\Contracts\Transport;
use Inverge\Nexus\Http\CurlTransport;
use Inverge\Nexus\Http\SyncDispatcher;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Services;

/**
 * Nexus Telescope — records the journey of every unit of work (an HTTP request,
 * a queued job, a console command, a scheduled task) and everything it did:
 * queries, outgoing HTTP, cache, events, jobs dispatched, mail, exceptions,
 * logs… Framework-agnostic; the Laravel and Symfony integrations drive it
 * automatically.
 *
 * ```php
 * $t = $nexus->telescope();
 * $t->start('request', ['method' => 'GET', 'uri' => '/orders/7'], ['traceparent' => $_SERVER['HTTP_TRACEPARENT'] ?? null]);
 * $t->query('select * from orders where id = ?', [7], 1.8, 'mysql');
 * $result = $t->span('price lookup', fn () => $pricing->quote($order));
 * $t->finish(['responseStatus' => 200]);
 * ```
 *
 * Nothing here ever throws into the host application: recording and delivery
 * failures are swallowed. Batches are tail-sampled at finish, so failed and
 * slow work is always kept whatever the sample rate.
 *
 * Batches nest (a `sync` queue job runs inside the request that dispatched it),
 * so the recorder keeps a STACK; entries always go to the innermost batch.
 */
final class Telescope
{
    public const VERSION = '1.8.0';

    /** @var list<Batch> */
    private array $stack = [];

    /** @var list<array<string, mixed>> finished batches waiting to be sent */
    private array $outbox = [];

    private bool $sending = false;

    private readonly SyncDispatcher $dispatcher;

    private readonly RemoteConfig $remote;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array{
     *   enabled?: bool,
     *   service?: string|null,
     *   host?: string|null,
     *   release?: string|null,
     *   timeout?: float,
     *   max_entries?: int,
     *   ignore_types?: list<string>,
     *   ignore_paths?: list<string>,
     *   ignore_statuses?: list<string|int>,
     *   redact_keys?: list<string>,
     *   sample_rate?: float|null,
     *   capture_response_bodies?: bool,
     *   body_size_limit?: int,
     *   cache_dir?: string|null,
     *   transport?: Transport,
     * } $options
     */
    public function __construct(private readonly NexusClient $client, array $options = [])
    {
        $this->options = $options + [
            'enabled' => true,
            'service' => null,
            'host' => null,
            'release' => null,
            'timeout' => 3.0,
            'max_entries' => 2000,
            'ignore_types' => [],
            'ignore_paths' => [],
            'ignore_statuses' => [],
            'redact_keys' => [],
            'sample_rate' => null,
            'capture_response_bodies' => true,
            'body_size_limit' => 64,
            'cache_dir' => null,
        ];

        $c = $client->config();
        // A short, dedicated timeout: telemetry delivery must never hold a worker.
        $deliveryConfig = new Config($c->apiKey, $c->baseUrl, (float) $this->options['timeout'], $c->defaultHeaders, $c->services);
        $this->dispatcher = new SyncDispatcher($deliveryConfig, $options['transport'] ?? new CurlTransport());
        $this->remote = new RemoteConfig($c->apiKey . '|' . $c->baseUrl, $this->options['cache_dir']);

        if (\function_exists('register_shutdown_function')) {
            register_shutdown_function(function (): void {
                $this->shutdown();
            });
        }
    }

    // ── state ───────────────────────────────────────────────────────────────

    /** Whether Telescope records at all (local switch, service switch, remote pause). */
    public function enabled(): bool
    {
        return (bool) $this->options['enabled']
            && $this->client->enabled(Services::TELESCOPE)
            && ($this->remote->get()['enabled'] ?? true) !== false;
    }

    /** Whether a unit of work is being recorded right now. */
    public function active(): bool
    {
        return $this->stack !== [];
    }

    public function current(): ?Batch
    {
        return $this->stack === [] ? null : $this->stack[\count($this->stack) - 1];
    }

    /** Whether this entry type is switched off locally or from the console. */
    public function ignores(string $type): bool
    {
        return \in_array($type, (array) $this->options['ignore_types'], true)
            || \in_array($type, (array) ($this->remote->get()['ignoreTypes'] ?? []), true);
    }

    /** Whether a request path is ignored (`/health`, `/horizon/*`, …). */
    public function ignoresPath(string $path): bool
    {
        $globs = array_merge((array) $this->options['ignore_paths'], (array) ($this->remote->get()['ignorePaths'] ?? []));
        foreach ($globs as $glob) {
            if (Redactor::globMatch((string) $glob, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a response status is ignored: an exact code (`200`), a class
     * (`2xx`) or a range (`301-304`), from the options or the console.
     */
    public function ignoresStatus(string|int $status): bool
    {
        $patterns = array_merge((array) $this->options['ignore_statuses'], (array) ($this->remote->get()['ignoreStatuses'] ?? []));
        foreach ($patterns as $pattern) {
            if (Redactor::statusMatch((string) $pattern, (string) $status)) {
                return true;
            }
        }

        return false;
    }

    public function redactor(): Redactor
    {
        return new Redactor(array_merge((array) $this->options['redact_keys'], (array) ($this->remote->get()['redactKeys'] ?? [])));
    }

    /** @return mixed */
    public function option(string $key)
    {
        return $this->options[$key] ?? null;
    }

    // ── units of work ───────────────────────────────────────────────────────

    /**
     * Open a unit of work. `$context` may carry an incoming `traceparent`
     * header, or an explicit `traceId` / `parentSpanId` (from a job payload),
     * plus `tags`, `user`, `name`, `sessionKey`, `deviceKey`.
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $context
     */
    public function start(string $type, array $content = [], array $context = []): ?string
    {
        if ($this->sending || !$this->enabled() || $this->ignores($type)) {
            return null;
        }
        try {
            $trace = TraceContext::parse(isset($context['traceparent']) ? (string) $context['traceparent'] : null);
            $parent = $this->current();
            $traceId = (string) ($context['traceId'] ?? $trace['traceId'] ?? $parent?->traceId ?? TraceContext::newTraceId());
            $parentSpanId = $context['parentSpanId'] ?? $trace['spanId'] ?? ($parent ? $parent->currentSpan() : null);

            $batch = new Batch(
                id: TraceContext::newSpanId(),
                type: $type,
                traceId: $traceId,
                parentSpanId: $parentSpanId !== null ? (string) $parentSpanId : null,
                // e.g. LARAVEL_START, so framework boot is part of the journey.
                startedAt: isset($context['startedAt']) ? (float) $context['startedAt'] : microtime(true),
                content: $this->redactor()->redact($content),
            );
            $batch->name = isset($context['name']) ? (string) $context['name'] : null;
            foreach ((array) ($context['tags'] ?? []) as $tag) {
                $batch->tags[] = (string) $tag;
            }
            if (isset($context['user']) && \is_array($context['user'])) {
                $batch->user = $context['user'];
            }
            $batch->sessionKey = isset($context['sessionKey']) ? (string) $context['sessionKey'] : null;
            $batch->deviceKey = isset($context['deviceKey']) ? (string) $context['deviceKey'] : null;
            $this->stack[] = $batch;

            return $batch->id;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Close the innermost unit of work: merge final content, decide whether to
     * keep it (tail sampling) and queue it for delivery. Sends when the
     * outermost one closes.
     *
     * @param array<string, mixed> $content
     */
    public function finish(array $content = [], ?string $status = null, ?string $name = null): void
    {
        $batch = array_pop($this->stack);
        if ($batch === null) {
            return;
        }
        try {
            $batch->durationMs = (microtime(true) - $batch->startedAt) * 1000;
            $batch->content = array_replace($batch->content, $this->redactor()->redact($content));
            if ($status !== null) {
                $batch->status = $status;
            }
            if ($name !== null) {
                $batch->name = $name;
            }
            // An ignored response status drops the request with every entry,
            // before anything leaves the app.
            $responseStatus = $batch->status ?? (isset($batch->content['responseStatus']) ? (string) $batch->content['responseStatus'] : null);
            $ignored = $batch->type === 'request' && $responseStatus !== null && $this->ignoresStatus($responseStatus);
            if (!$ignored && $this->keep($batch)) {
                $this->outbox[] = $batch->toArray();
            }
        } catch (\Throwable) {
            // never break the host app
        }
        if ($this->stack === [] || \count($this->outbox) >= 20) {
            $this->flush();
        }
    }

    /** Close every open unit of work (e.g. a fatal error ended the process). */
    public function finishAll(array $content = []): void
    {
        while ($this->stack !== []) {
            $this->finish($content);
        }
    }

    // ── entries ─────────────────────────────────────────────────────────────

    /**
     * Record an entry in the current unit of work.
     *
     * Options: `durationMs`, `startedAt` (microtime float), `status`, `name`,
     * `tags`, `parentId`, `id`.
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $opts
     */
    public function record(string $type, array $content = [], array $opts = []): ?string
    {
        $batch = $this->current();
        if ($batch === null || $this->sending || $this->ignores($type)) {
            return null;
        }
        try {
            if (\count($batch->entries) >= (int) $this->options['max_entries']) {
                $batch->dropped++;

                return null;
            }
            $now = microtime(true);
            $duration = isset($opts['durationMs']) ? (float) $opts['durationMs'] : null;
            $startedAt = isset($opts['startedAt']) ? (float) $opts['startedAt'] : ($duration !== null ? $now - $duration / 1000 : $now);
            $id = isset($opts['id']) ? (string) $opts['id'] : TraceContext::newSpanId();
            $entry = [
                'id' => $id,
                'parentId' => $opts['parentId'] ?? $batch->currentSpan(),
                'type' => $type,
                'offsetMs' => round(max(0.0, ($startedAt - $batch->startedAt) * 1000), 3),
                'content' => $this->redactor()->redact($content),
            ];
            if ($duration !== null) {
                $entry['durationMs'] = round($duration, 3);
            }
            if (isset($opts['status'])) {
                $entry['status'] = (string) $opts['status'];
            }
            if (isset($opts['name'])) {
                $entry['name'] = (string) $opts['name'];
            }
            if (!empty($opts['tags'])) {
                $entry['tags'] = array_values(array_map('strval', (array) $opts['tags']));
            }
            $batch->entries[$id] = $entry;
            if ($type === 'exception' && ($content['handled'] ?? false) !== true) {
                $batch->failed = true;
            }

            return $id;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Run `$fn` inside a named span; entries recorded meanwhile nest under it.
     *
     * @template T
     * @param callable(): T $fn
     * @param array<string, mixed> $content
     * @return T
     */
    public function span(string $name, callable $fn, array $content = [], string $type = 'span')
    {
        $id = $this->openSpan($type, $name, $content);
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->closeSpan($id, ['error' => $e->getMessage()]);
            $id = null;
            throw $e;
        } finally {
            if ($id !== null) {
                $this->closeSpan($id);
            }
        }
    }

    /**
     * Open a span manually (pair with {@see closeSpan()}); returns its id.
     *
     * @param array<string, mixed> $content
     */
    public function openSpan(string $type, string $name, array $content = []): ?string
    {
        $batch = $this->current();
        if ($batch === null) {
            return null;
        }
        $id = $this->record($type, $content + ['name' => $name], ['name' => $name]);
        if ($id !== null) {
            $batch->spans[] = ['id' => $id, 'at' => microtime(true)];
        }

        return $id;
    }

    /** @param array<string, mixed> $extra */
    public function closeSpan(?string $id, array $extra = []): void
    {
        $batch = $this->current();
        if ($id === null || $batch === null) {
            return;
        }
        foreach (array_reverse($batch->spans, true) as $i => $span) {
            if ($span['id'] !== $id) {
                continue;
            }
            array_splice($batch->spans, $i, 1);
            if (isset($batch->entries[$id])) {
                $batch->entries[$id]['durationMs'] = round((microtime(true) - $span['at']) * 1000, 3);
                if ($extra !== []) {
                    $batch->entries[$id]['content'] = array_replace($batch->entries[$id]['content'], $this->redactor()->redact($extra));
                }
            }

            return;
        }
    }

    /** @param array<int|string, mixed> $bindings @param array<string, mixed> $extra */
    public function query(string $sql, array $bindings, float $durationMs, ?string $connection = null, array $extra = []): ?string
    {
        return $this->record('query', ['sql' => $sql, 'bindings' => self::scalarize($bindings), 'connection' => $connection] + $extra, ['durationMs' => $durationMs]);
    }

    /** @param array<string, mixed> $context */
    public function exception(\Throwable $e, bool $handled = false, array $context = []): ?string
    {
        return $this->record('exception', [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'handled' => $handled,
            'stack' => self::frames($e),
            'previous' => $e->getPrevious() ? $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage() : null,
            'context' => $context ?: null,
        ]);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): ?string
    {
        return $this->record('log', ['level' => strtolower($level), 'message' => $message, 'context' => self::scalarize($context)], ['status' => strtolower($level)]);
    }

    public function tag(string ...$tags): void
    {
        $batch = $this->current();
        if ($batch !== null) {
            foreach ($tags as $t) {
                $batch->tags[] = $t;
            }
        }
    }

    /**
     * The authenticated user of the current unit of work. `id` becomes the
     * `Auth:<id>` tag and correlates the journey into Nexus Sessions.
     *
     * @param array<string, mixed> $user
     */
    public function user(array $user): void
    {
        $batch = $this->current();
        if ($batch !== null && $user !== []) {
            $batch->user = $user;
        }
    }

    // ── propagation ─────────────────────────────────────────────────────────

    /** A W3C `traceparent` for an outgoing call made from the current point. */
    public function traceparent(?string $spanId = null): ?string
    {
        $batch = $this->current();

        return $batch ? TraceContext::format($batch->traceId, $spanId ?? $batch->currentSpan()) : null;
    }

    /**
     * What a queued job carries so its execution joins this trace.
     *
     * @return array{traceId:string,parentSpanId:string}|null
     */
    public function propagation(?string $spanId = null): ?array
    {
        $batch = $this->current();

        return $batch ? ['traceId' => $batch->traceId, 'parentSpanId' => $spanId ?? $batch->currentSpan()] : null;
    }

    // ── delivery ────────────────────────────────────────────────────────────

    /** Send queued batches now. Never throws. */
    public function flush(): void
    {
        if ($this->outbox === [] || $this->sending) {
            return;
        }
        $batches = $this->outbox;
        $this->outbox = [];
        $this->sending = true;
        try {
            $payload = [
                'service' => $this->options['service'],
                'host' => $this->options['host'] ?? (gethostname() ?: null),
                'release' => $this->options['release'],
                'sdk' => ['name' => 'nexus-php', 'version' => self::VERSION],
                'batches' => $batches,
            ];
            $payload = array_filter($payload, static fn ($v) => $v !== null);
            $response = $this->dispatcher->dispatch('POST', '/partner/telescope', $payload);
            if (isset($response['config']) && \is_array($response['config'])) {
                $this->remote->put($response['config']);
            }
        } catch (\Throwable) {
            // dropped — telemetry must never break the app
        } finally {
            $this->sending = false;
        }
    }

    private function shutdown(): void
    {
        try {
            if ($this->stack !== []) {
                $error = error_get_last();
                $fatal = $error !== null && \in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
                if ($fatal && ($batch = $this->current()) !== null && !$this->hasException($batch, (string) $error['message'])) {
                    $this->record('exception', [
                        'class' => 'FatalError',
                        'message' => (string) $error['message'],
                        'file' => (string) $error['file'],
                        'line' => (int) $error['line'],
                        'handled' => false,
                    ]);
                    $batch->failed = true;
                }
                $this->finishAll();
            }
            $this->flush();
        } catch (\Throwable) {
        }
    }

    /** Whether the framework already reported this fatal (it often does, via its own shutdown handler). */
    private function hasException(Batch $batch, string $message): bool
    {
        foreach ($batch->entries as $e) {
            if ($e['type'] === 'exception' && ($e['content']['message'] ?? null) === $message) {
                return true;
            }
        }

        return false;
    }

    /** Tail sampling: failures, slow work and monitored tags always survive. */
    private function keep(Batch $batch): bool
    {
        $remote = $this->remote->get();
        if ($batch->failed || $batch->isFailure()) {
            return true;
        }
        if ($batch->durationMs >= (float) ($remote['slowRequestMs'] ?? 1000)) {
            return true;
        }
        $monitored = (array) ($remote['monitoredTags'] ?? []);
        $userTag = isset($batch->user['id']) ? 'Auth:' . $batch->user['id'] : null;
        foreach ($monitored as $tag) {
            if (\in_array($tag, $batch->tags, true) || $tag === $userTag) {
                return true;
            }
        }
        $rate = $this->options['sample_rate'] ?? $remote['sampleRate'] ?? 1.0;

        return (float) $rate >= 1.0 || mt_rand() / mt_getrandmax() < (float) $rate;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Stack frames in the shape Nexus Errors renders.
     *
     * @return list<array{function:string,filename:string,lineno:int}>
     */
    public static function frames(\Throwable $e): array
    {
        $frames = [['function' => '{main}', 'filename' => $e->getFile(), 'lineno' => $e->getLine()]];
        foreach (\array_slice($e->getTrace(), 0, 60) as $f) {
            $frames[] = [
                'function' => (isset($f['class']) ? $f['class'] . ($f['type'] ?? '::') : '') . ($f['function'] ?? '?'),
                'filename' => (string) ($f['file'] ?? '[internal]'),
                'lineno' => (int) ($f['line'] ?? 0),
            ];
        }
        $frames[0]['function'] = $frames[1]['function'] ?? '{main}';

        return $frames;
    }

    /**
     * Make values JSON-safe: objects become their class (or id for models),
     * resources their type, long strings are clipped.
     *
     * @return mixed
     */
    public static function scalarize(mixed $value, int $depth = 0)
    {
        if ($depth > 6) {
            return '…';
        }
        if ($value === null || \is_bool($value) || \is_int($value) || \is_float($value)) {
            return $value;
        }
        if (\is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                return '[binary ' . \strlen($value) . ' bytes]';
            }

            return mb_strlen($value) > 2000 ? mb_substr($value, 0, 2000) . '…' : $value;
        }
        if (\is_array($value)) {
            $out = [];
            $i = 0;
            foreach ($value as $k => $v) {
                if ($i++ >= 100) {
                    $out['…'] = (\count($value) - 100) . ' more';
                    break;
                }
                $out[$k] = self::scalarize($v, $depth + 1);
            }

            return $out;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if (\is_object($value)) {
            if (method_exists($value, 'getKey')) {
                return $value::class . ':' . (string) $value->getKey();
            }
            if ($value instanceof \JsonSerializable) {
                return self::scalarize($value->jsonSerialize(), $depth + 1);
            }
            if ($value instanceof \Stringable) {
                return (string) $value;
            }

            return $value::class;
        }
        if (\is_resource($value)) {
            return 'resource(' . get_resource_type($value) . ')';
        }

        return null;
    }
}

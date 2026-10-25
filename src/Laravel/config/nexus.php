<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Nexus API key & endpoint
    |--------------------------------------------------------------------------
    */
    'api_key' => env('NEXUS_API_KEY', ''),
    'base_url' => env('NEXUS_BASE_URL', 'https://services.inverge.net'),
    'timeout' => env('NEXUS_TIMEOUT', 10.0),

    /*
    |--------------------------------------------------------------------------
    | Services on/off
    |--------------------------------------------------------------------------
    | Switch a Nexus product off without removing the code that uses it — e.g.
    | NEXUS_LIVE_ACTIVITIES_ENABLED=false stops Live Activities everywhere
    | (the Laravel channel, the builders, plain calls). A switched-off service
    | makes no network call and never throws: writes are skipped, reads return
    | their defaults. NEXUS_ENABLED=false turns every service off.
    */
    'enabled' => env('NEXUS_ENABLED', true),

    'services' => [
        'realtime' => env('NEXUS_REALTIME_ENABLED', true),
        'events' => env('NEXUS_EVENTS_ENABLED', true),
        'errors' => env('NEXUS_ERRORS_ENABLED', true),
        'logs' => env('NEXUS_LOGS_ENABLED', true),
        'sessions' => env('NEXUS_SESSIONS_ENABLED', true),
        'flags' => env('NEXUS_FLAGS_ENABLED', true),
        'remote_config' => env('NEXUS_REMOTE_CONFIG_ENABLED', true),
        'links' => env('NEXUS_LINKS_ENABLED', true),
        'surveys' => env('NEXUS_SURVEYS_ENABLED', true),
        'push' => env('NEXUS_PUSH_ENABLED', true),
        'in_app' => env('NEXUS_IN_APP_ENABLED', true),
        'live_activities' => env('NEXUS_LIVE_ACTIVITIES_ENABLED', true),
        'telescope' => env('NEXUS_TELESCOPE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queued delivery
    |--------------------------------------------------------------------------
    | When enabled, the `nexus.queue` client (and the log handler, if it uses
    | the queue) delivers telemetry via Laravel's queue so it never blocks the
    | request. Resolve it with app('nexus.queue') for fire-and-forget sends.
    */
    'queue' => [
        'enabled' => env('NEXUS_QUEUE', false),
        'connection' => env('NEXUS_QUEUE_CONNECTION'),
        'queue' => env('NEXUS_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log forwarding
    |--------------------------------------------------------------------------
    | Two ways to ship your logs to Nexus Logs (both batch + use the queue above
    | when enabled):
    |
    | 1) A dedicated `nexus` LOG CHANNEL (recommended). It is auto-registered, so
    |    you can send logs to Nexus INSTEAD of the file with no other setup:
    |
    |        LOG_CHANNEL=nexus            # nothing goes to laravel.log
    |
    |    …or keep the file too by stacking (config/logging.php):
    |
    |        'stack' => ['driver' => 'stack', 'channels' => ['single', 'nexus']],
    |
    | 2) `logging.enabled` below — piggybacks a handler on your DEFAULT channel
    |    (logs go to BOTH the file and Nexus). Leave it off if you use the
    |    channel above, or you'll double-send.
    */
    'logging' => [
        'enabled' => env('NEXUS_LOGGING', false),
        'level' => env('NEXUS_LOG_LEVEL', 'debug'),
        'flush_at' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic error capture
    |--------------------------------------------------------------------------
    | When true, unhandled exceptions logged by the framework (they carry the
    | Throwable in the log context) are reported to Nexus Errors with a full
    | stacktrace. Requires `logging.enabled`.
    */
    'capture_errors' => env('NEXUS_CAPTURE_ERRORS', true),

    /*
    |--------------------------------------------------------------------------
    | Telescope — request journeys
    |--------------------------------------------------------------------------
    | Records every request, queued job, artisan command and scheduled task
    | with everything it did — queries (with N+1 / duplicate / slow detection),
    | models, events, middleware & controller timing, outgoing HTTP, cache,
    | Redis, mail, notifications, gates, views, dumps, exceptions and logs —
    | and shows it in the Nexus console under Telescope.
    |
    | Delivery happens after the response is sent (PHP-FPM / Octane), so it
    | adds no latency. Jobs and outgoing HTTP calls carry a W3C `traceparent`,
    | so a request, the jobs it dispatched and the services it called appear as
    | one distributed trace.
    |
    | Recording can be paused, sampled and filtered from the console without a
    | deploy (Telescope → Settings). NEXUS_TELESCOPE_ENABLED=false switches it
    | off here.
    */
    'telescope' => [
        // The service name shown in traces (several apps can share an environment).
        'service' => env('NEXUS_SERVICE_NAME', env('APP_NAME')),
        'release' => env('NEXUS_RELEASE'),

        // null = use the console's sample rate. Failed, slow and monitored
        // journeys are always kept.
        'sample_rate' => env('NEXUS_TELESCOPE_SAMPLE_RATE'),

        // Seconds to wait for Nexus when delivering (after the response).
        'timeout' => env('NEXUS_TELESCOPE_TIMEOUT', 3.0),

        // Never recorded. Globs against the request path.
        'ignore_paths' => [
            '/telescope*', '/horizon*', '/_debugbar*', '/pulse*', '/up', '/livewire/livewire.js*',
        ],

        // Long-running or noisy commands (their jobs are recorded individually).
        'ignore_commands' => [
            'queue:work', 'queue:listen', 'queue:restart', 'horizon', 'horizon:*', 'schedule:run',
            'schedule:work', 'schedule:finish', 'octane:*', 'reverb:*', 'serve', 'tinker', 'package:discover',
            'vendor:publish', 'config:*', 'route:*', 'view:*', 'event:*', 'optimize*', 'list', 'help',
            'pail', 'nexus:*',
        ],

        // Extra keys redacted from headers/payloads (passwords, tokens and
        // cookies are always redacted).
        'redact_keys' => [],

        // Record response bodies (JSON is decoded; HTML is summarised).
        'capture_response_bodies' => true,
        'body_size_limit' => 64, // KB

        // Record the app file:line that ran each query / gate check / dump.
        'locations' => true,

        // Per batch; the rest is counted, not stored.
        'max_entries' => 2000,

        'watchers' => [
            'requests' => true,
            'middleware' => true,
            'queries' => true,
            'models' => true,
            'events' => true,
            'jobs' => true,
            'batches' => true,
            'commands' => true,
            'schedule' => true,
            'exceptions' => true,
            'logs' => true,
            'http' => true,
            'cache' => true,
            'redis' => true,
            'mail' => true,
            'notifications' => true,
            'gates' => true,
            'views' => true,
            'dumps' => true,
        ],
    ],
];

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
];

<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Nexus API key & endpoint
    |--------------------------------------------------------------------------
    */
    'api_key' => env('NEXUS_API_KEY', ''),
    'base_url' => env('NEXUS_BASE_URL', 'https://nexus.inverge.net'),
    'timeout' => env('NEXUS_TIMEOUT', 10.0),

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
    | Attach a Monolog handler to the default log channel that ships your logs
    | to Nexus Logs (batched). Uses the queue above when it is enabled.
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

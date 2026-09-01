<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Nexus API key
    |--------------------------------------------------------------------------
    | Your project/environment API key (nxs_...). Sent as the `x-api-key` header.
    */
    'api_key' => env('NEXUS_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | API base URL
    |--------------------------------------------------------------------------
    | The Nexus origin. Partner endpoints live under `/partner`.
    */
    'base_url' => env('NEXUS_BASE_URL', 'https://api.nexus.inverge.net'),

    /*
    |--------------------------------------------------------------------------
    | Request timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => env('NEXUS_TIMEOUT', 10.0),
];

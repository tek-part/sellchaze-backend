<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | Browser origins allowed to call this API.
    |
    | Set CORS_ALLOWED_ORIGINS on the server to a comma-separated list when you
    | need more than one — e.g. covering http + https during an SSL migration,
    | or an apex + www pair:
    |   CORS_ALLOWED_ORIGINS=http://sellchaze.com,https://sellchaze.com,https://www.sellchaze.com
    |
    | When it is unset it falls back to FRONTEND_URL (a single origin, also used
    | elsewhere for building links), then to the local dev origin. The origin
    | must match the browser's exactly — scheme included: http and https are
    | different origins.
    */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

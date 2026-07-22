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
    | Precedence: CORS_ALLOWED_ORIGINS (server .env) → FRONTEND_URL (server .env)
    | → the built-in default below. The default deliberately lists BOTH the
    | production storefront (http + https, apex + www) and the local dev origin,
    | so a deployed server is CORS-correct even before anyone sets an env var —
    | this is what makes the fix ship in the repo rather than depend on a manual
    | .env edit. Setting CORS_ALLOWED_ORIGINS on the server still overrides it.
    |
    | The matched origin is reflected back exactly, so a request from
    | http://sellchaze.com receives Access-Control-Allow-Origin: http://sellchaze.com.
    | Scheme matters: http and https are different origins.
    */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            env(
                'FRONTEND_URL',
                'http://sellchaze.com,https://sellchaze.com,http://www.sellchaze.com,https://www.sellchaze.com,http://localhost:5173',
            ),
        )),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

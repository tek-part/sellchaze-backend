<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'product_ai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_PRODUCT_MODEL') ?: env('OPENAI_FUNNEL_MODEL'),
        'per_day' => (int) env('PRODUCT_AI_PER_STORE_PER_DAY', 20),
    ],

    'funnel_ai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_FUNNEL_MODEL'),
        'per_day' => (int) env('FUNNEL_AI_PER_STORE_PER_DAY', 20),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'telegram-bot-api' => [
        'token' => env('TELEGRAM_BOT_TOKEN', 'YOUR BOT TOKEN HERE'),
    ],

    /** Base for legacy product images synced from Wigpleasure ({base}/upload/...). */
    'wigpleasure_storefront_url' => rtrim((string) env('WIGPLEASURE_STOREFRONT_URL', 'https://wigpleasure.com'), '/'),

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'currency_rates' => [
        'url' => env('CURRENCY_RATES_API_URL', 'https://api.exchangerate.host/latest'),
        'api_key' => env('CURRENCY_RATES_API_KEY'),
        'base' => env('CURRENCY_RATES_API_BASE', 'USD'),
        'timeout' => (int) env('CURRENCY_RATES_API_TIMEOUT', 15),
    ],

    /**
     * Wavex / Wawp: optional public HTTPS base for files under storage/app/public (same as disk "public" URL),
     * without changing APP_URL for the rest of the app. Example: https://abc.ngrok-free.app/storage
     */
    'wavex' => [
        'public_storage_url' => ($w = trim((string) env('WAVEX_PUBLIC_STORAGE_URL', ''))) !== '' ? rtrim($w, '/') : null,
    ],

    'bosta' => [
        'webhook_base_url' => env('BOSTA_WEBHOOK_BASE_URL', env('APP_URL')),
    ],

    /* Platform-owned checkout for paid marketplace themes. Never use a merchant store's Stripe keys here. */
    'theme_marketplace' => [
        'stripe_secret' => env('THEME_MARKETPLACE_STRIPE_SECRET'),
        'stripe_webhook_secret' => env('THEME_MARKETPLACE_STRIPE_WEBHOOK_SECRET'),
        'success_url' => env(
            'THEME_MARKETPLACE_SUCCESS_URL',
            rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/store/themes?theme_purchase=success&session_id={CHECKOUT_SESSION_ID}'
        ),
        'cancel_url' => env(
            'THEME_MARKETPLACE_CANCEL_URL',
            rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/store/themes?theme_purchase=cancelled'
        ),
    ],
];

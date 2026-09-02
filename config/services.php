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

    'api_key' => env('API_KEY'),
    /** Optional; Wigpleasure→Sellchase HTTP sync no longer requires a key. Kept for `ApiKeyMiddleware` if re-attached to routes. */
    'sellchase_api_key' => env('SELLCHASE_API_KEY'),
    /** Default owner for Wigpleasure-synced orders (user id with Merchant or Admin role). Optional if at least one Merchant or Admin exists. */
    'sellchase_merchant_user_id' => env('SELLCHASE_MERCHANT_USER_ID'),
    /**
     * Allow matching API_KEY / SELLCHASE_API_KEY from .env (without users.api_key).
     * Default: true when APP_ENV=local, false otherwise. Override with SELLCHASE_ALLOW_GLOBAL_API_KEY.
     */
    'sellchase_allow_global_api_key' => filter_var(
        env(
            'SELLCHASE_ALLOW_GLOBAL_API_KEY',
            env('APP_ENV', 'production') === 'local' ? 'true' : 'false'
        ),
        FILTER_VALIDATE_BOOLEAN
    ),

    /**
     * When store sync cannot resolve any supplier, use this users.id (must have Supplier role).
     * If SELLCHASE_FALLBACK_SUPPLIER_SKIP_INVITE_CHECK is false, the supplier must still be an accepted partner of the merchant.
     */
    'sellchase_fallback_supplier_user_id' => env('SELLCHASE_FALLBACK_SUPPLIER_USER_ID'),
    'sellchase_fallback_supplier_skip_invite_check' => filter_var(
        env(
            'SELLCHASE_FALLBACK_SUPPLIER_SKIP_INVITE_CHECK',
            env('APP_ENV', 'production') === 'local' ? 'true' : 'false'
        ),
        FILTER_VALIDATE_BOOLEAN
    ),

    /**
     * Last resort for sync: use the first non-Admin Supplier in the database (local default true).
     * Disable in production multi-tenant unless you intentionally run a single-supplier hub.
     */
    'sellchase_sync_use_first_supplier_when_empty' => filter_var(
        env(
            'SELLCHASE_SYNC_USE_FIRST_SUPPLIER_WHEN_EMPTY',
            env('APP_ENV', 'production') === 'local' ? 'true' : 'false'
        ),
        FILTER_VALIDATE_BOOLEAN
    ),

    /** Public storefront base URL for product links (no trailing slash). */
    'wigpleasure_storefront_url' => rtrim((string) env('WIGPLEASURE_STOREFRONT_URL', 'https://wigpleasure.com'), '/'),

    /**
     * Pull sync: Wigpleasure base URL including locale prefix, e.g. http://127.0.0.1:8001/ar
     * Endpoints: {base}/api/sellchase-export/...
     */
    'wigpleasure_pull_base_url' => rtrim((string) env('WIGPLEASURE_PULL_BASE_URL', ''), '/'),

    /** Same value as SELLCHASE_INBOUND_KEY on Wigpleasure; sent as X-Sellchase-Inbound-Key. */
    'wigpleasure_inbound_key' => env('WIGPLEASURE_INBOUND_KEY', env('SELLCHASE_INBOUND_KEY', '')),

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

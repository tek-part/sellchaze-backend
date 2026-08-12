<?php

return [

    'name' => env('APP_NAME', 'Sellchaze'),

    /** SPA origin for Google Cloud Console “Authorized JavaScript origins” (Sign in with Google). */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /**
     * Absolute path of the public sitemap.xml the SPA serves. The backend
     * regenerates it from live directory data so suppliers who register after
     * the last frontend build still get indexed. Unset = generation disabled.
     */
    'sitemap_path' => env('SELLCHASE_SITEMAP_PATH'),

    /**
     * IndexNow key (Bing/Yandex/Seznam) used to announce sitemap changes.
     * Google no longer accepts pings — it reads the Sitemap: line in robots.txt.
     * Unset = no ping is attempted.
     */
    'indexnow_key' => env('SELLCHASE_INDEXNOW_KEY'),

    'jwt' => [
        'secret' => env('JWT_SECRET') ?: '',
        'access_ttl_minutes' => (int) env('JWT_ACCESS_TTL_MINUTES', 1440),
        'refresh_ttl_hours' => (int) env('JWT_REFRESH_TTL_HOURS', 24),
    ],

    /**
     * Storefront (Phase 2): base domain used to build store subdomains,
     * e.g. base_domain "sellchase.com" => "nike.sellchase.com".
     */
    'storefront' => [
        'base_domain' => strtolower(env('SELLCHASE_STOREFRONT_BASE_DOMAIN', 'sellchaze.com')),

        /*
         | StorefrontUrlGenerator inputs — deliberately decoupled from APP_URL.
         | APP_URL addresses the dashboard/API app; storefronts live on tenant
         | hosts (and, in split-port dev, a different port), so their links are
         | built from these values instead.
         |
         | scheme/port   — the ACTIVE ENVIRONMENT for owner-facing, directly
         |                 openable links (dashboard "view store", owner
         |                 previews). Dev: http + 8002. Prod: https + no port.
         | canonical_scheme — scheme for PUBLIC, customer-facing links (SEO
         |                 canonicals, email / notification links). Always https.
         | preview_domain — optional dedicated base for owner preview links.
         */
        'scheme' => strtolower((string) env('SELLCHASE_STOREFRONT_SCHEME', 'https')),
        'port' => env('SELLCHASE_STOREFRONT_PORT'),
        'canonical_scheme' => strtolower((string) env('SELLCHASE_STOREFRONT_CANONICAL_SCHEME', 'https')),
        'preview_domain' => env('SELLCHASE_STOREFRONT_PREVIEW_DOMAIN'),

        /**
         * Force customer-facing storefront traffic onto https with a 301.
         * Null (the default) means "on outside local/testing", so development
         * over http is unaffected. Behind a TLS-terminating proxy this requires
         * TRUSTED_PROXIES to be set, otherwise X-Forwarded-Proto is ignored and
         * the redirect would loop.
         */
        'force_https' => env('SELLCHASE_STOREFRONT_FORCE_HTTPS'),

        /** Seconds to cache host -> store resolution. */
        'resolve_cache_ttl' => (int) env('SELLCHASE_STOREFRONT_RESOLVE_CACHE_TTL', 300),

        /*
        |----------------------------------------------------------------------
        | Custom domains (Sprint 2)
        |----------------------------------------------------------------------
        | SSL is provider-agnostic. `provider` selects one implementation of
        | App\Services\Stores\Ssl\SslProvider; Let's Encrypt is just one ACME CA,
        | never assumed. Default is `none` so an unconfigured deployment reports
        | "SSL not configured" honestly instead of appearing healthy.
        */
        'ssl' => [
            'provider' => env('SELLCHASE_SSL_PROVIDER', 'none'),

            /** Renew this many days before expiry. */
            'renew_before_days' => (int) env('SELLCHASE_SSL_RENEW_BEFORE_DAYS', 30),

            /** Owner notification thresholds, in days remaining. */
            'expiry_notice_days' => [90, 60, 30, 15, 7, 1],

            /** Give up automatic renewal after this many consecutive failures. */
            'max_renewal_attempts' => (int) env('SELLCHASE_SSL_MAX_RENEWAL_ATTEMPTS', 8),

            'acme' => [
                'directory' => env('SELLCHASE_ACME_DIRECTORY', 'https://acme-v02.api.letsencrypt.org/directory'),
                'email' => env('SELLCHASE_ACME_EMAIL'),
                'issue_command' => env('SELLCHASE_ACME_ISSUE_COMMAND'),
                'renew_command' => env('SELLCHASE_ACME_RENEW_COMMAND'),
                'revoke_command' => env('SELLCHASE_ACME_REVOKE_COMMAND'),
                'timeout' => (int) env('SELLCHASE_ACME_TIMEOUT', 180),
            ],

            'cloudflare' => [
                'api_token' => env('CLOUDFLARE_API_TOKEN'),
                'zone_id' => env('CLOUDFLARE_ZONE_ID'),
                'base_uri' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),
                'validation_method' => env('CLOUDFLARE_SSL_VALIDATION_METHOD', 'http'),
            ],
        ],

        'domains' => [
            /** Queue connection/name for all domain work. Never runs inline. */
            'queue' => env('SELLCHASE_DOMAIN_QUEUE', 'domains'),

            /** Challenge token lifetime — a stale TXT record cannot verify forever. */
            'token_ttl_hours' => (int) env('SELLCHASE_DOMAIN_TOKEN_TTL_HOURS', 168),

            /** Abuse protection. */
            'max_per_store' => (int) env('SELLCHASE_DOMAIN_MAX_PER_STORE', 25),
            'max_verification_attempts' => (int) env('SELLCHASE_DOMAIN_MAX_VERIFY_ATTEMPTS', 10),
            'lock_minutes' => (int) env('SELLCHASE_DOMAIN_LOCK_MINUTES', 60),

            /** Disable a verified domain after this many consecutive daily failures. */
            'stale_after_failures' => (int) env('SELLCHASE_DOMAIN_STALE_AFTER_FAILURES', 5),

            /** The CNAME target tenants are told to point at. */
            'cname_target' => env('SELLCHASE_DOMAIN_CNAME_TARGET'),

            /** The A record target tenants are told to point at (apex domains). */
            'a_target' => env('SELLCHASE_DOMAIN_A_TARGET'),
        ],

        /** Homepage data cache TTL (StorefrontService). */
        'homepage_cache_ttl' => (int) env('SELLCHASE_STOREFRONT_HOMEPAGE_CACHE_TTL', 60),

        /**
         * Local-dev only: which store a bare localhost host resolves to (slug or id). When empty,
         * the resolver auto-picks the first store that has an active catalogue. Never used outside
         * the `local` environment. See StoreDomainResolver::devFallbackStore().
         */
        'dev_store' => env('STOREFRONT_DEV_STORE'),

        /**
         * Phase 4B: React SSR runtime endpoint (Node service). When null/empty,
         * the storefront renders via the Blade section fallback (Hybrid).
         */
        'ssr_url' => env('SELLCHASE_STOREFRONT_SSR_URL'),
        'ssr_timeout' => (int) env('SELLCHASE_STOREFRONT_SSR_TIMEOUT', 2),

        /** Full-page cache TTL (seconds). */
        'page_cache_ttl' => (int) env('SELLCHASE_STOREFRONT_PAGE_CACHE_TTL', 300),

        /** Provider-neutral responsive image URL adapter. Empty URL preserves originals. */
        'images' => [
            'transformer_url' => env('SELLCHASE_IMAGE_TRANSFORMER_URL'),
            'signing_secret' => env('SELLCHASE_IMAGE_TRANSFORMER_SECRET'),
            'quality' => (int) env('SELLCHASE_IMAGE_QUALITY', 82),
        ],
    ],

    /**
     * Phase 4D: platform capabilities used for theme-version compatibility checks.
     */
    'platform' => [
        'version' => env('SELLCHASE_PLATFORM_VERSION', '1.0.0'),
        'features' => ['sections', 'seo', 'ssr', 'settings'],
    ],

    /** Disk used for theme marketplace assets (S3-ready). */
    'theme_assets_disk' => env('SELLCHASE_THEME_ASSETS_DISK', 'public'),
];

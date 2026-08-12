<?php

return [
    'fixture_email' => env('PERFORMANCE_EMAIL', 'performance@sellchaze.test'),
    'fixture_password' => env('PERFORMANCE_PASSWORD', 'PerformanceOnly123!'),
    /*
    | Workload isolation limits. The generic API limiter is intentionally a
    | broad IP safety net; authenticated read-heavy surfaces get an additional
    | organization bucket after JWT authentication has resolved the user.
    */
    'api_ip_per_minute' => (int) env('API_IP_PER_MINUTE', 1200),
    'tenant_read_per_minute' => (int) env('TENANT_READ_PER_MINUTE', 300),
    'storefront_read_per_minute' => (int) env('STOREFRONT_READ_PER_MINUTE', 600),
    'storefront_ip_per_minute' => (int) env('STOREFRONT_IP_PER_MINUTE', 1800),
];

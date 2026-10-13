<?php

return [
    'fees_enabled' => (bool) env('PLATFORM_WALLET_FEES_ENABLED', false),
    'commissions_enabled' => (bool) env('PLATFORM_REFERRAL_COMMISSIONS_ENABLED', true),
    'fee_milli' => (int) env('PLATFORM_ORDER_FEE_MILLI', 40),
    'commission_basis_points' => (int) env('PLATFORM_REFERRAL_BASIS_POINTS', 2000),
    'minimum_referred_stores' => (int) env('PLATFORM_REFERRAL_MINIMUM_STORES', 10),
    'debt_order_limit' => (int) env('PLATFORM_WALLET_DEBT_ORDER_LIMIT', 5),
    'subscriptions' => [
        'enabled' => (bool) env('MERCHANT_SUBSCRIPTIONS_ENABLED', false),
        'monthly_milli' => (int) env('MERCHANT_SUBSCRIPTION_MONTHLY_MILLI', 100000),
        'yearly_milli' => (int) env('MERCHANT_SUBSCRIPTION_YEARLY_MILLI', 600000),
    ],
    'fawaterak' => [
        'enabled' => (bool) env('PLATFORM_WALLET_FAWATERAK_ENABLED', false),
        'test_mode' => (bool) env('PLATFORM_WALLET_FAWATERAK_TEST_MODE', true),
        'api_key' => env('PLATFORM_WALLET_FAWATERAK_API_KEY', ''),
        'vendor_key' => env('PLATFORM_WALLET_FAWATERAK_VENDOR_KEY', ''),
        'webhook_base_url' => env('PLATFORM_WALLET_WEBHOOK_BASE_URL', env('APP_URL', '')),
    ],
];

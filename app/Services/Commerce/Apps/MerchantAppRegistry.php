<?php

namespace App\Services\Commerce\Apps;

final class MerchantAppRegistry
{
    /** App store keys with first-party settings, mapped to their settings service. */
    public const APPS = [
        'social-proof' => SocialProof::class,
        'country-restriction' => CountryRestriction::class,
        'fake-orders' => OrderProtection::class,
        'bundles' => ProductBundles::class,
    ];

    public static function for(string $key): MerchantAppSettings
    {
        abort_unless(isset(self::APPS[$key]), 404);

        return app(self::APPS[$key]);
    }
}

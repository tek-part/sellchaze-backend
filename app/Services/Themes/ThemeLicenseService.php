<?php

namespace App\Services\Themes;

use App\Models\Store;
use App\Models\StoreThemeLicense;
use App\Models\Theme;
use RuntimeException;

class ThemeLicenseService
{
    public function isLicensed(Store $store, Theme $theme): bool
    {
        if ((float) $theme->price <= 0 || $theme->license_type === 'free') return true;
        $license = StoreThemeLicense::query()->where('store_id', $store->id)->where('theme_id', $theme->id)->first();

        return $license?->isActive() ?? false;
    }

    public function assertCanInstall(Store $store, Theme $theme, ?int $actorId = null): StoreThemeLicense
    {
        if ((float) $theme->price <= 0 || $theme->license_type === 'free') {
            return StoreThemeLicense::updateOrCreate(
                ['store_id' => $store->id, 'theme_id' => $theme->id],
                ['acquired_by_user_id' => $actorId, 'status' => 'active', 'source' => 'free', 'price_paid' => 0, 'currency' => $theme->currency, 'starts_at' => now(), 'expires_at' => null],
            );
        }

        $license = StoreThemeLicense::query()->where('store_id', $store->id)->where('theme_id', $theme->id)->first();
        if (! $license?->isActive()) {
            throw new RuntimeException('An active theme license is required before installing this premium theme.');
        }

        return $license;
    }
}

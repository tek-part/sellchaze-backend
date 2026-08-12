<?php

namespace App\Services\Features;

use App\Models\FeatureFlag;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

class FeatureFlagService
{
    public function enabled(string $key, ?Organization $organization = null): bool
    {
        $flag = Cache::remember("feature-flag:{$key}", 60, fn () => FeatureFlag::query()
            ->where('key', $key)
            ->first());

        if (! $flag instanceof FeatureFlag) {
            return false;
        }

        if ($organization === null) {
            return $flag->enabled_by_default;
        }

        $override = $flag->organizations()
            ->whereKey($organization->getKey())
            ->where(function ($query) {
                $query->whereNull('organization_feature_flags.expires_at')
                    ->orWhere('organization_feature_flags.expires_at', '>', now());
            })
            ->first();

        return $override === null
            ? $flag->enabled_by_default
            : (bool) $override->pivot->getAttribute('enabled');
    }

    public function forget(string $key): void
    {
        Cache::forget("feature-flag:{$key}");
    }
}

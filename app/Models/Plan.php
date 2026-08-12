<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan (billing/commerce tier). Matches the live `plans` schema:
 * monthly/yearly pricing, feature flags, and numeric quotas (max_products, …).
 *
 * @property int $id
 * @property string $slug
 * @property string $name_en
 * @property string $name_ar
 * @property string|null $description_en
 * @property string|null $description_ar
 * @property string|null $target
 * @property string $price_monthly
 * @property string $price_yearly
 * @property string $currency
 * @property int $trial_days
 * @property bool $is_active
 * @property bool $is_featured
 * @property int $sort_order
 * @property array|null $features
 * @property array|null $quotas
 */
class Plan extends Model
{
    protected $fillable = [
        'slug', 'name_en', 'name_ar', 'description_en', 'description_ar',
        'target', 'price_monthly', 'price_yearly', 'currency', 'trial_days',
        'is_active', 'is_featured', 'sort_order', 'features', 'quotas',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'trial_days' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'features' => 'array',
            'quotas' => 'array',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<PlanEntitlement, $this> */
    public function entitlementValues(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    /** @return HasMany<PlanPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function nameLocalized(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    /** Boolean feature flag lookup (true only if explicitly truthy). */
    public function hasFeature(string $key): bool
    {
        return ! empty(($this->features ?? [])[$key]);
    }

    /** Numeric quota lookup; null = unlimited / not configured. */
    public function quota(string $key): ?int
    {
        $q = $this->quotas ?? [];
        if (! array_key_exists($key, $q) || $q[$key] === null || $q[$key] === '') {
            return null;
        }

        return (int) $q[$key];
    }

    public function priceFor(string $cycle): float
    {
        return $cycle === 'yearly' ? (float) $this->price_yearly : (float) $this->price_monthly;
    }

    /** Monthly feed-posting limit; null = unlimited. Sourced from the quotas map. */
    public function postLimitMonthly(): ?int
    {
        return $this->quota('posts_monthly');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan. `post_limit_monthly` null = unlimited.
 *
 * @property int $id
 * @property string $slug
 * @property string $name_en
 * @property string $name_ar
 * @property int|null $post_limit_monthly
 * @property string $price
 * @property string $currency
 * @property int $trial_days
 */
class Plan extends Model
{
    protected $fillable = [
        'slug', 'name_en', 'name_ar', 'post_limit_monthly', 'price', 'currency', 'trial_days', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            'post_limit_monthly' => 'integer',
            'price' => 'decimal:2',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function nameLocalized(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    public function isUnlimited(): bool
    {
        return $this->post_limit_monthly === null;
    }
}

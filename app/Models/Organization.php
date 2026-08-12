<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    public const ROLES = ['owner', 'admin', 'manager', 'member'];

    protected $fillable = [
        'name', 'slug', 'legal_name', 'type', 'status', 'country_code',
        'default_locale', 'default_currency', 'timezone', 'metadata', 'headline', 'about',
        'website', 'logo_url', 'cover_url', 'locations', 'capabilities', 'featured_products',
        'certificates', 'is_verified', 'verified_at', 'verified_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array', 'locations' => 'array', 'capabilities' => 'array',
            'featured_products' => 'array', 'certificates' => 'array',
            'is_verified' => 'boolean', 'verified_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['role', 'status', 'permissions', 'store_ids', 'joined_at'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Sector, $this> */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class, 'organization_sectors')->withPivot('is_primary')->withTimestamps();
    }

    /** @return HasMany<Store, $this> */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return BelongsToMany<FeatureFlag, $this> */
    public function featureFlags(): BelongsToMany
    {
        return $this->belongsToMany(FeatureFlag::class, 'organization_feature_flags')
            ->withPivot(['enabled', 'configuration', 'expires_at'])
            ->withTimestamps();
    }

    /** @return HasMany<ProcurementRequest, $this> */
    public function procurementRequests(): HasMany
    {
        return $this->hasMany(ProcurementRequest::class, 'buyer_organization_id');
    }

    /** @return HasMany<ProcurementQuote, $this> */
    public function procurementQuotes(): HasMany
    {
        return $this->hasMany(ProcurementQuote::class, 'supplier_organization_id');
    }
}

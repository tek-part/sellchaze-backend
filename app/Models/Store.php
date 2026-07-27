<?php

namespace App\Models;

use App\Models\Scopes\ProductScope;
use App\Models\Scopes\StoreScope;
use App\Services\Storefront\StorefrontPageCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Store extends Model
{
    public const STATUSES = ['draft', 'active', 'suspended'];

    public const OWNER_TYPES = ['merchant', 'supplier'];

    protected $fillable = [
        'owner_user_id', 'owner_type', 'name', 'slug', 'description',
        'logo', 'banner', 'email', 'phone', 'currency', 'status',
        'theme_id', 'theme_settings',
    ];

    protected $casts = [
        'theme_settings' => 'array',
    ];

    /** Task 6: any store change (status, name, settings...) invalidates its page cache. */
    protected static function booted(): void
    {
        static::saved(function (Store $store): void {
            app(StorefrontPageCache::class)->flushStore($store->id);
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<StoreDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(StoreDomain::class);
    }

    /**
     * Domains that may actually be served — verified only.
     *
     * A relation (rather than ->domains()->servable()) so the query is typed
     * end-to-end and every caller shares one definition of "servable".
     *
     * @return HasMany<StoreDomain, $this>
     */
    public function servableDomains(): HasMany
    {
        return $this->domains()->where('status', StoreDomain::STATUS_VERIFIED);
    }

    // The catalog is unified and store-less: a store simply surfaces its OWNER's
    // catalog (user_id = owner_user_id). These relations opt out of ProductScope so
    // they resolve without a CurrentStore context (eager loading, counts).
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'user_id', 'owner_user_id')
            ->withoutGlobalScope(ProductScope::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'user_id', 'owner_user_id')
            ->withoutGlobalScope(ProductScope::class);
    }

    public function primaryDomain(): HasMany
    {
        return $this->domains()->where('is_primary', true);
    }

    /** Active-theme cache pointer (stores.theme_id -> themes.id). */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class, 'theme_id');
    }

    public function storeThemes(): HasMany
    {
        return $this->hasMany(StoreTheme::class);
    }

    public function activeStoreTheme(): HasMany
    {
        return $this->storeThemes()->where('status', 'active');
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }

    public function bannerUrl(): ?string
    {
        return $this->banner ? Storage::disk('public')->url($this->banner) : null;
    }
}

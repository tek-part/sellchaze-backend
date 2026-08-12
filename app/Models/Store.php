<?php

namespace App\Models;

use App\Models\Scopes\ProductScope;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Stores\StoreDomainResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Store extends Model
{
    public const STATUSES = ['draft', 'active', 'suspended'];

    public const OWNER_TYPES = ['merchant', 'supplier'];

    protected $fillable = [
        'organization_id', 'owner_user_id', 'owner_type', 'is_primary', 'name', 'slug', 'description',
        'logo', 'banner', 'email', 'phone', 'currency', 'status',
        'default_locale', 'supported_locales', 'supported_currencies', 'timezone',
        'tax_enabled', 'tax_rate', 'tax_prices_include', 'shipping_enabled',
        'shipping_flat_rate', 'shipping_free_over',
        'theme_id', 'theme_settings',
    ];

    protected $casts = [
        'theme_settings' => 'array',
        'is_primary' => 'boolean',
        'supported_locales' => 'array',
        'supported_currencies' => 'array',
        'tax_enabled' => 'boolean',
        'tax_rate' => 'decimal:3',
        'tax_prices_include' => 'boolean',
        'shipping_enabled' => 'boolean',
        'shipping_flat_rate' => 'decimal:2',
        'shipping_free_over' => 'decimal:2',
    ];

    /** Task 6: any store change (status, name, settings...) invalidates its page cache. */
    protected static function booted(): void
    {
        static::saved(function (Store $store): void {
            app(StorefrontPageCache::class)->flushStore($store->id);
            $resolver = app(StoreDomainResolver::class);
            if ($store->slug) {
                $resolver->forgetHost($store->slug.'.'.$resolver->baseDomain());
            }
            $store->domains()->pluck('host')->each(fn (string $host) => $resolver->forgetHost($host));
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
        return $this->hasMany(Product::class, 'store_id')
            ->withoutGlobalScope(ProductScope::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'store_id')
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

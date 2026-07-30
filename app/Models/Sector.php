<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the global, platform-owned sector taxonomy powering the public Supplier Directory.
 *
 * Unlike {@see Category}, this model is intentionally NOT tenant-scoped — sectors are shared across
 * the whole platform (depth 0 = sector, depth 1 = specialty). Bilingual name/SEO/intro fields are
 * resolved to the active locale by the *Localized accessors below so controllers/resources can stay
 * locale-agnostic.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $slug
 * @property string $name_en
 * @property string $name_ar
 * @property string|null $icon
 * @property int $depth
 * @property int $position
 * @property bool $is_active
 * @property-read int|null $suppliers_count
 */
class Sector extends Model
{
    protected $hidden = ['updated_at'];

    protected $fillable = [
        'parent_id', 'slug', 'name_en', 'name_ar', 'icon', 'depth', 'position', 'is_active',
        'seo_title_en', 'seo_title_ar', 'seo_description_en', 'seo_description_ar', 'intro_en', 'intro_ar',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'depth' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Sector, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'parent_id');
    }

    /** @return HasMany<Sector, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Sector::class, 'parent_id')->orderBy('position');
    }

    /** Suppliers/merchants linked to this sector. @return BelongsToMany<User, $this> */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'supplier_sector', 'sector_id', 'user_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /** Only top-level sectors (the 8 cards on the directory home). */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Locale-resolved display name. */
    public function nameLocalized(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    public function introLocalized(?string $locale = null): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->intro_ar : $this->intro_en;
    }

    public function seoTitleLocalized(?string $locale = null): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->seo_title_ar : $this->seo_title_en;
    }

    public function seoDescriptionLocalized(?string $locale = null): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->seo_description_ar : $this->seo_description_en;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

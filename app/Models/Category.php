<?php

namespace App\Models;

use App\Models\Concerns\HasImageUrl;
use App\Models\Concerns\HasStoreTenancy;
use App\Models\Concerns\HasTranslations;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocalizedValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * The single canonical category model. Serves both the global B2B taxonomy (store_id = NULL) and the
 * multi-tenant storefront taxonomy (store_id = the store), isolated by {@see ProductScope} via
 * {@see HasStoreTenancy}. Retains the legacy bilingual name_en/name_ar, the wigpleasure_category_id
 * external key, and the name-derivation hook; adds hierarchy, slug, imagery, visibility and SEO.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_en
 * @property string|null $name_ar
 * @property int|null $wigpleasure_category_id
 * @property int|null $store_id
 * @property int|null $parent_id
 * @property string|null $slug
 * @property string|null $description
 * @property string|null $image
 * @property string|null $icon
 * @property bool $is_active
 * @property bool $is_featured
 * @property int $position
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property array|null $translations
 * @property-read int|null $products_count
 */
class Category extends Model
{
    use HasImageUrl;
    use HasStoreTenancy;
    use HasTranslations {
        mirrorTranslations as mirrorTranslationsBase;
    }

    /** Attributes carried per-locale in the `translations` json (see HasTranslations). */
    protected array $translatable = ['name', 'description'];

    protected $hidden = ['updated_at', 'deleted_at'];

    protected $fillable = [
        'name', 'name_en', 'name_ar', 'wigpleasure_category_id',
        // canonical superset (from Category)
        'user_id', 'store_id', 'parent_id', 'slug', 'description', 'image', 'icon',
        'is_active', 'is_featured', 'position', 'seo_title', 'seo_description', 'translations',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'position' => 'integer',
            'translations' => 'array',
        ];
    }

    /**
     * Categories carry the legacy bilingual `name_en`/`name_ar` columns next to the
     * generic `translations.name` map; both must always agree. Rules:
     *  1. A dirty `translations` payload wins: `translations.name.en/ar` are copied
     *     into `name_en`/`name_ar` (an entry missing from the map leaves the column alone).
     *  2. Otherwise a dirty `name_en`/`name_ar` is copied into `translations.name`.
     *  3. Whatever remains empty on either side is filled from the other, then the
     *     generic mirror (default locale → `name`) runs as for every model.
     */
    public function mirrorTranslations(): void
    {
        $map = LocalizedValue::normalize($this->translationsFor('name'), $this->translationDefaultLocale());
        $columns = ['en' => 'name_en', 'ar' => 'name_ar'];

        if ($this->isDirty('translations')) {
            foreach ($columns as $locale => $column) {
                if (($map[$locale] ?? '') !== '') {
                    $this->{$column} = $map[$locale];
                }
            }
        } else {
            foreach ($columns as $locale => $column) {
                $value = trim((string) ($this->{$column} ?? ''));
                if ($value !== '' && ($this->isDirty($column) || ($map[$locale] ?? '') === '')) {
                    $map[$locale] = $value;
                }
            }
        }

        foreach ($columns as $locale => $column) {
            if (trim((string) ($this->{$column} ?? '')) === '' && ($map[$locale] ?? '') !== '') {
                $this->{$column} = $map[$locale];
            }
        }

        if ($map !== [] && $map !== $this->translationsFor('name')) {
            $this->setTranslations('name', $map);
        }

        $this->mirrorTranslationsBase();
    }

    /** `name_ar`/`name_en` back the generic lookup when no `translations` entry exists. */
    public function translated(string $attribute, ?string $locale = null): ?string
    {
        $locale ??= app(LocaleContext::class)->current();
        $picked = LocalizedValue::pick($this->translationsFor($attribute), $locale, $this->translationDefaultLocale());
        if ($picked !== '') {
            return $picked;
        }
        if ($attribute === 'name' && ($this->name_en !== null || $this->name_ar !== null)) {
            $label = $this->labelForLocale($locale);
            if ($label !== '') {
                return $label;
            }
        }
        $base = $this->getAttribute($attribute);

        return $base === null ? null : (string) $base;
    }

    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            $en = trim((string) ($category->name_en ?? ''));
            $ar = trim((string) ($category->name_ar ?? ''));
            if ($en !== '' && $ar === '') {
                $category->name_ar = $en;
            }
            if ($ar !== '' && $en === '') {
                $category->name_en = $ar;
            }
            $category->name = $en !== '' ? $en : ($ar !== '' ? $ar : (string) ($category->name ?? ''));
        });

        static::saved(fn () => Cache::forget('categories_list'));
        static::deleted(fn () => Cache::forget('categories_list'));
    }

    public static function cachedAll()
    {
        return Cache::remember('categories_list', 300, fn () => static::orderBy('name_en')->orderBy('name')->get());
    }

    public function labelForLocale(?string $lang): string
    {
        $code = strtolower(substr((string) $lang, 0, 2));

        return $code === 'ar'
            ? (string) ($this->name_ar ?: $this->name_en ?: $this->name ?? '')
            : (string) ($this->name_en ?: $this->name_ar ?: $this->name ?? '');
    }

    // ---- Legacy relationships (unchanged) ------------------------------------------------------

    public function product()
    {
        return $this->hasOne(Product::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    // ---- Canonical superset relationships (from Category) ----------------------------------
    // store() + scopeForStore() come from the shared InteractsWithStore trait.

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')
            ->orderBy('position')->orderBy('id');
    }
}

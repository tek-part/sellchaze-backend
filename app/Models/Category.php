<?php

namespace App\Models;

use App\Models\Concerns\HasImageUrl;
use App\Models\Concerns\HasStoreTenancy;
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

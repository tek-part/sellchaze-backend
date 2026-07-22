<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Attribute extends Model
{
    use HasFactory;

    protected $hidden = ['updated_at', 'deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'type'];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('attributes_list'));
        static::deleted(fn () => Cache::forget('attributes_list'));
    }

    public static function cachedAll()
    {
        return Cache::remember('attributes_list', 300, fn () => static::with('attribute_values')->orderBy('name')->get());
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<AttributeValues, $this> */
    public function attribute_values(): HasMany
    {
        return $this->hasMany(AttributeValues::class);
    }

    /** @return HasMany<ProductAttributes, $this> */
    public function product_attributes(): HasMany
    {
        return $this->hasMany(ProductAttributes::class);
    }
}

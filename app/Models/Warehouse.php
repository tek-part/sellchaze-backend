<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'code',
        'name',
        'name_en',
        'name_ar',
        'is_active',
        'is_default',
        'position',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $warehouse) {
            if ($warehouse->is_default) {
                static::query()->where('id', '!=', $warehouse->id)->update(['is_default' => false]);
            }
        });
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(WarehouseInventory::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function defaultWarehouse(): ?self
    {
        return static::query()->active()->where('is_default', true)->first()
            ?? static::query()->active()->orderBy('position')->first();
    }
}

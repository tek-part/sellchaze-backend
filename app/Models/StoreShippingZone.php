<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shipping zone: countries plus optional regions (governorates/cities) with their own rates.
 * Always query with an explicit store_id; zones are managed from the merchant dashboard where
 * no storefront tenant is bound.
 *
 * @property string $id
 * @property int $store_id
 * @property array{ar:string,en:string} $name
 * @property list<string> $countries
 * @property list<array{id:string,name:array{ar:string,en:string},country:string}> $regions
 * @property bool $enabled
 * @property int $position
 */
class StoreShippingZone extends Model
{
    use HasUuids;

    protected $fillable = ['store_id', 'name', 'countries', 'regions', 'enabled', 'position'];

    protected $casts = ['name' => 'array', 'countries' => 'array', 'regions' => 'array', 'enabled' => 'boolean', 'position' => 'integer'];

    /** @return HasMany<StoreShippingRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(StoreShippingRate::class, 'zone_id')->orderBy('position')->orderBy('created_at');
    }
}

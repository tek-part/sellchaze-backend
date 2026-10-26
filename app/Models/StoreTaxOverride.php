<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-country (optionally per-region) tax rate that replaces the store-wide rate for
 * matching shipping addresses. Whether prices include tax is inherited from the store.
 *
 * @property string $id
 * @property int $store_id
 * @property string $country
 * @property string|null $region_id
 * @property array{ar:string,en:string} $name
 * @property string $rate
 * @property bool $enabled
 */
class StoreTaxOverride extends Model
{
    use HasUuids;

    protected $fillable = ['store_id', 'country', 'region_id', 'name', 'rate', 'enabled'];

    protected $casts = ['name' => 'array', 'rate' => 'decimal:3', 'enabled' => 'boolean'];
}

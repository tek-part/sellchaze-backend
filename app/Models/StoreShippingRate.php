<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A zone rate: flat, by order price band or by weight band. Bands include the minimum and
 * exclude the maximum (a null maximum has no upper limit).
 *
 * @property string $id
 * @property int $store_id
 * @property string $zone_id
 * @property array{ar:string,en:string} $name
 * @property string $type
 * @property string|null $min_subtotal
 * @property string|null $max_subtotal
 * @property int|null $min_weight_grams
 * @property int|null $max_weight_grams
 * @property string $price
 * @property string|null $free_over
 * @property array{ar:string,en:string}|null $delivery_time
 * @property string|null $cod_fee
 * @property bool $enabled
 * @property int $position
 */
class StoreShippingRate extends Model
{
    use HasUuids;

    public const TYPES = ['flat', 'price', 'weight'];

    protected $fillable = ['store_id', 'zone_id', 'name', 'type', 'min_subtotal', 'max_subtotal', 'min_weight_grams', 'max_weight_grams',
        'price', 'free_over', 'delivery_time', 'cod_fee', 'enabled', 'position'];

    protected $casts = ['name' => 'array', 'delivery_time' => 'array', 'min_subtotal' => 'decimal:2', 'max_subtotal' => 'decimal:2',
        'min_weight_grams' => 'integer', 'max_weight_grams' => 'integer', 'price' => 'decimal:2', 'free_over' => 'decimal:2',
        'cod_fee' => 'decimal:2', 'enabled' => 'boolean', 'position' => 'integer'];
}

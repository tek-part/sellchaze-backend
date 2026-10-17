<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed>|null $credentials */
class StorePaymentGateway extends Model
{
    protected $fillable = ['store_id', 'gateway', 'enabled', 'test_mode', 'credentials', 'sort_order', 'notes', 'presentation'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'test_mode' => 'boolean', 'credentials' => 'encrypted:array', 'sort_order' => 'integer', 'presentation' => 'array'];
    }
}

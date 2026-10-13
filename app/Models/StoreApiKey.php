<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreApiKey extends Model
{
    use HasUuids, SoftDeletes;

    public const PERMISSIONS = ['products:read', 'products:create', 'products:update', 'products:delete', 'orders:read', 'orders:update', 'orders:delete', 'categories:read', 'categories:create', 'categories:update', 'categories:delete', 'shipping_areas'];

    protected $guarded = [];

    protected $hidden = ['token_hash', 'creation_receipt', 'receipt_expires_at', 'request_key'];

    protected $attributes = ['version' => 1];

    protected $casts = ['permissions' => 'array', 'enabled' => 'boolean', 'version' => 'integer', 'creation_receipt' => 'encrypted', 'receipt_expires_at' => 'datetime', 'last_used_at' => 'datetime'];
}

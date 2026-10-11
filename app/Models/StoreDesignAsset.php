<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreDesignAsset extends Model
{
    use SoftDeletes;

    protected $fillable = ['store_id', 'public_id', 'name', 'kind', 'path', 'size_bytes', 'checksum_sha256'];

    protected $casts = ['size_bytes' => 'integer'];

    public function publicPath(): string
    {
        return '/api/v1/storefront/design-assets/'.$this->public_id.'.'.$this->kind;
    }
}

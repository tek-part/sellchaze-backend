<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreMediaAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id', 'uploaded_by_user_id', 'disk', 'path', 'original_name', 'mime',
        'size_bytes', 'width', 'height', 'alt_text', 'checksum_sha256',
    ];

    protected $casts = [
        'size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer',
    ];
}

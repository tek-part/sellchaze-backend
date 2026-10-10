<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * @property string $id
 * @property int $store_id
 * @property int $product_id
 * @property string $field_key
 * @property string $token_hash
 * @property string $path
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 */
class ProductPersonalizationUpload extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['path', 'token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'claimed_at' => 'datetime', 'store_id' => 'integer', 'product_id' => 'integer', 'size' => 'integer'];

    public function downloadUrl(): string
    {
        return URL::temporarySignedRoute('personalization.download', now()->addMinutes(30), ['store' => $this->store_id, 'upload' => $this->id], false);
    }
}

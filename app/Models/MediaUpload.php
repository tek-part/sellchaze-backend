<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $media_asset_id
 * @property int $user_id
 * @property int $chunk_size
 * @property int $total_chunks
 * @property int $uploaded_chunks
 * @property string $status
 * @property Carbon|null $expires_at
 * @property MediaAsset $asset
 * @property Collection<int, MediaUploadPart> $parts
 */
class MediaUpload extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'media_asset_id', 'user_id', 'chunk_size', 'total_chunks', 'uploaded_chunks', 'status', 'expires_at', 'last_error'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /** @return HasMany<MediaUploadPart, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(MediaUploadPart::class);
    }
}

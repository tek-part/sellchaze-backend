<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $uuid
 * @property string $disk
 * @property string|null $object_key
 * @property string $original_name
 * @property string $kind
 * @property string $mime
 * @property int $size_bytes
 * @property string|null $checksum_sha256
 * @property string $status
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $metadata
 * @property string|null $url
 * @property Pivot $pivot
 * @property Collection<int, MediaVariant> $variants
 */
class MediaAsset extends Model
{
    protected $fillable = ['uuid', 'user_id', 'organization_id', 'disk', 'object_key', 'original_name', 'kind', 'mime', 'size_bytes', 'checksum_sha256', 'status', 'width', 'height', 'duration_ms', 'metadata', 'failure_reason', 'processed_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'size_bytes' => 'integer', 'processed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<MediaVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->object_key ? Storage::disk($this->disk)->url($this->object_key) : null;
    }
}

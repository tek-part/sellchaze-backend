<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MediaVariant extends Model
{
    protected $fillable = ['media_asset_id', 'profile', 'disk', 'object_key', 'mime', 'size_bytes', 'width', 'height', 'bitrate', 'metadata'];
    protected function casts(): array { return ['metadata' => 'array']; }
    public function asset(): BelongsTo { return $this->belongsTo(MediaAsset::class, 'media_asset_id'); }
    public function getUrlAttribute(): string { return Storage::disk($this->disk)->url($this->object_key); }
}

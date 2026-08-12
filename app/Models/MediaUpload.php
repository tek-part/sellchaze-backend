<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaUpload extends Model
{
    use HasUuids;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'media_asset_id', 'user_id', 'chunk_size', 'total_chunks', 'uploaded_chunks', 'status', 'expires_at', 'last_error'];
    protected function casts(): array { return ['expires_at' => 'datetime']; }
    public function asset(): BelongsTo { return $this->belongsTo(MediaAsset::class, 'media_asset_id'); }
    public function parts(): HasMany { return $this->hasMany(MediaUploadPart::class); }
}

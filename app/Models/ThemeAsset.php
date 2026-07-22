<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ThemeAsset extends Model
{
    public const TYPES = ['preview', 'gallery', 'logo'];

    protected $fillable = ['theme_id', 'type', 'path', 'disk', 'width', 'height', 'position'];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function url(): ?string
    {
        return $this->path ? Storage::disk($this->disk ?: 'public')->url($this->path) : null;
    }
}

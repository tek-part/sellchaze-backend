<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $part_number
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property string $temporary_path
 */
class MediaUploadPart extends Model
{
    protected $fillable = ['media_upload_id', 'part_number', 'size_bytes', 'checksum_sha256', 'temporary_path'];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(MediaUpload::class, 'media_upload_id');
    }
}

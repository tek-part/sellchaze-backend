<?php

namespace App\Support\Community;

use App\Models\MediaAsset;

class MediaPresenter
{
    public static function asset(MediaAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'uuid' => $asset->uuid,
            'kind' => $asset->kind,
            'mime' => $asset->mime,
            'name' => $asset->original_name,
            'size_bytes' => (int) $asset->size_bytes,
            'status' => $asset->status,
            'url' => $asset->url,
            'width' => $asset->width,
            'height' => $asset->height,
            'duration_ms' => $asset->duration_ms,
            'metadata' => $asset->metadata ?? [],
            'variants' => $asset->relationLoaded('variants')
                ? $asset->variants->mapWithKeys(fn ($variant) => [$variant->profile => [
                    'url' => $variant->url,
                    'mime' => $variant->mime,
                    'width' => $variant->width,
                    'height' => $variant->height,
                ]])->all()
                : [],
        ];
    }
}

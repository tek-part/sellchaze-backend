<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Single canonical implementation of image-URL resolution for the catalog models
 * `Product` and `Category` (and the store-scoped media/brand/collection models).
 * Absolute URLs (external/CDN/mock) pass through; stored paths resolve on the public disk.
 */
trait HasImageUrl
{
    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'http')
            ? $this->image
            : Storage::disk('public')->url($this->image);
    }
}

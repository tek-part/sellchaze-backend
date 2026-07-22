<?php

namespace App\Services\Themes;

use App\Models\Theme;
use App\Models\ThemeAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Theme marketplace asset management (Task 7). Storage-abstracted (public disk by
 * default, S3-ready via config) with mime/size/dimension validation.
 */
class ThemeAssetService
{
    private const MAX_BYTES = 4 * 1024 * 1024;         // 4 MB

    private const MIN_DIMENSION = 64;

    private const MAX_DIMENSION = 4000;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private function disk(): string
    {
        return (string) config('sellchase.theme_assets_disk', 'public');
    }

    public function store(Theme $theme, UploadedFile $file, string $type, int $position = 0): ThemeAsset
    {
        if (! in_array($type, ThemeAsset::TYPES, true)) {
            throw new RuntimeException("Invalid asset type: {$type}");
        }
        $this->validate($file);

        [$width, $height] = getimagesize($file->getPathname()) ?: [null, null];

        $path = $file->store('theme-assets/'.$theme->id, $this->disk());

        return ThemeAsset::create([
            'theme_id' => $theme->id,
            'type' => $type,
            'path' => $path,
            'disk' => $this->disk(),
            'width' => $width,
            'height' => $height,
            'position' => $position,
        ]);
    }

    public function delete(ThemeAsset $asset): void
    {
        if ($asset->path) {
            Storage::disk($asset->disk ?: 'public')->delete($asset->path);
        }
        $asset->delete();
    }

    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('Asset exceeds the 4 MB size limit.');
        }
        if (! in_array($file->getMimeType(), self::MIMES, true)) {
            throw new RuntimeException('Asset must be a JPEG, PNG, or WebP image.');
        }
        $size = getimagesize($file->getPathname());
        if ($size === false) {
            throw new RuntimeException('Asset is not a valid image.');
        }
        [$w, $h] = $size;
        if ($w < self::MIN_DIMENSION || $h < self::MIN_DIMENSION || $w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION) {
            throw new RuntimeException('Asset dimensions must be between 64px and 4000px.');
        }
    }
}

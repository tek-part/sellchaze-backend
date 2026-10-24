<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;

/**
 * Resized WebP copies of images on the public disk, Shopify-CDN style.
 *
 * A derivative lives at /storage/_img/w{width}/{source path}.webp. The first request misses the file,
 * falls through Apache to Laravel, and is rendered once with GD; every later request is a static file
 * that Apache serves with a one-year immutable cache. Widths come from a fixed ladder, so the number
 * of files per source image is bounded.
 */
class ImageDerivatives
{
    public const WIDTHS = [160, 320, 480, 640, 800, 960, 1280, 1600, 1920];

    public const DIRECTORY = '_img';

    private const MAX_SOURCE_BYTES = 30 * 1024 * 1024;

    private const MAX_SOURCE_PIXELS = 40_000_000;

    private const QUALITY = 80;

    /** Smallest ladder width at or above the requested one (the largest if none is). */
    public static function snap(int $width): int
    {
        foreach (self::WIDTHS as $candidate) {
            if ($candidate >= $width) {
                return $candidate;
            }
        }

        return self::WIDTHS[array_key_last(self::WIDTHS)];
    }

    /** Path of a public-disk image relative to the disk root, or null when the URL is not ours or not resizable. */
    public function relativePath(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $base = (string) parse_url((string) config('filesystems.disks.public.url'), PHP_URL_PATH);
        $prefix = rtrim($base, '/').'/';
        if (! str_starts_with($path, $prefix)) {
            return null;
        }
        $host = parse_url((string) $url, PHP_URL_HOST);
        $ownHost = parse_url((string) config('filesystems.disks.public.url'), PHP_URL_HOST);
        if ($host !== null && $ownHost !== null && strcasecmp($host, $ownHost) !== 0) {
            return null;
        }
        $relative = rawurldecode(substr($path, strlen($prefix)));

        return $this->validRelative($relative) ? $relative : null;
    }

    /** Public URL of the derivative for a source URL, or null when the source cannot be resized. */
    public function url(?string $sourceUrl, int $width): ?string
    {
        $relative = $this->relativePath($sourceUrl);
        if ($relative === null) {
            return null;
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));

        return rtrim((string) config('filesystems.disks.public.url'), '/').'/'.self::DIRECTORY.'/w'.self::snap($width).'/'.$encoded.'.webp';
    }

    /**
     * Render (once) and return the absolute path of a derivative; null when the request is invalid
     * or the source is missing, too large or not a raster image GD can read.
     */
    public function render(int $width, string $relative): ?string
    {
        if (! in_array($width, self::WIDTHS, true) || ! $this->validRelative($relative)) {
            return null;
        }
        $root = realpath(Storage::disk('public')->path(''));
        $source = $root ? realpath($root.'/'.$relative) : false;
        if (! $root || ! $source || ! str_starts_with($source, $root.DIRECTORY_SEPARATOR) || ! is_file($source)
            || filesize($source) > self::MAX_SOURCE_BYTES) {
            return null;
        }
        $target = $root.'/'.self::DIRECTORY.'/w'.$width.'/'.$relative.'.webp';
        if (is_file($target) && filemtime($target) >= filemtime($source)) {
            return $target;
        }

        $info = @getimagesize($source);
        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_SOURCE_PIXELS) {
            return null;
        }
        $image = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default => false,
        };
        if (! $image) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->applyExifOrientation($image, $source);
        }

        // Never upscale: a small source is only re-encoded to WebP.
        $sourceWidth = imagesx($image);
        if ($sourceWidth > $width) {
            $scaled = imagescale($image, $width, -1, IMG_BICUBIC);
            imagedestroy($image);
            if (! $scaled) {
                return null;
            }
            $image = $scaled;
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $directory = dirname($target);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            imagedestroy($image);

            return null;
        }
        // Write then rename, so a concurrent request never serves a half-written file.
        $temporary = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
        $written = imagewebp($image, $temporary, self::QUALITY);
        imagedestroy($image);
        if (! $written || ! @rename($temporary, $target)) {
            @unlink($temporary);

            return null;
        }

        return $target;
    }

    private function validRelative(string $relative): bool
    {
        return $relative !== ''
            && ! str_starts_with($relative, self::DIRECTORY.'/')
            && ! str_contains($relative, '..')
            && preg_match('#^[A-Za-z0-9_\-./]+\.(png|jpe?g|webp)$#i', $relative) === 1;
    }

    private function applyExifOrientation(\GdImage $image, string $source): \GdImage
    {
        $orientation = function_exists('exif_read_data') ? (int) (@exif_read_data($source)['Orientation'] ?? 1) : 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}

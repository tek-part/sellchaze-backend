<?php

namespace App\Services\Storefront;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;
use Throwable;

/**
 * Product media pipeline — turns a source image (remote royalty-free URL or a local file) into a
 * permanent, optimised local media asset: it stores an optimised JPEG original, a WebP variant and a
 * square thumbnail on the public disk, and extracts intrinsic metadata (width, height, dominant colour,
 * byte size). The result is a metadata array ready to persist as a ProductMedia row, so the
 * storefront serves everything locally (no external hotlinks / offline-safe).
 *
 * Downloads are cached by content so the same source URL is fetched once even when reused across
 * products, keeping a large catalog's image work bounded.
 */
class ProductMediaPipeline
{
    /** @var array<string, string> in-process cache: source url → raw bytes */
    private array $cache = [];

    public function __construct(
        private readonly string $disk = 'public',
        private readonly int $maxWidth = 1200,
        private readonly int $thumbSize = 400,
        private readonly int $quality = 82,
    ) {
    }

    /**
     * Fetch + optimise + store an image under `$dir/$base.*`. Returns the media metadata array, or null
     * if the source could not be fetched/decoded (caller decides how to degrade).
     *
     * @return array{path:string,webp_path:string,thumbnail_path:string,width:int,height:int,dominant_color:string,size:int,mime:string}|null
     */
    public function store(string $source, string $dir, string $base): ?array
    {
        $bytes = $this->fetch($source);
        if ($bytes === null) {
            return null;
        }

        try {
            $img = Image::make($bytes);
        } catch (Throwable) {
            return null;
        }

        // Downscale oversized sources (keep aspect ratio, never upscale).
        if ($img->width() > $this->maxWidth) {
            $img->resize($this->maxWidth, null, function ($c): void {
                $c->aspectRatio();
                $c->upsize();
            });
        }

        $disk = Storage::disk($this->disk);
        $dir = trim($dir, '/');
        $disk->makeDirectory($dir);

        $jpgPath = "$dir/$base.jpg";
        $webpPath = "$dir/$base.webp";
        $thumbPath = "$dir/{$base}_thumb.webp";

        $jpg = (string) $img->encode('jpg', $this->quality);
        $disk->put($jpgPath, $jpg);
        $disk->put($webpPath, (string) $img->encode('webp', $this->quality));

        $thumb = Image::make($bytes)->fit($this->thumbSize, $this->thumbSize);
        $disk->put($thumbPath, (string) $thumb->encode('webp', $this->quality));

        return [
            'path' => $jpgPath,
            'webp_path' => $webpPath,
            'thumbnail_path' => $thumbPath,
            'width' => $img->width(),
            'height' => $img->height(),
            'dominant_color' => $this->dominantColor($bytes),
            'size' => strlen($jpg),
            'mime' => 'image/jpeg',
        ];
    }

    /** Fetch bytes from a remote URL (cached) or a local file path. Null on failure. */
    private function fetch(string $source): ?string
    {
        if (isset($this->cache[$source])) {
            return $this->cache[$source];
        }

        try {
            if (str_starts_with($source, 'http')) {
                $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: SellChase-Demo-Seeder\r\n"]]);
                $bytes = @file_get_contents($source, false, $ctx);
            } else {
                $bytes = is_file($source) ? file_get_contents($source) : false;
            }
        } catch (Throwable) {
            $bytes = false;
        }

        if ($bytes === false || $bytes === '' || strlen($bytes) < 512) {
            return null;
        }

        // Bound the process cache so a big run doesn't balloon memory.
        if (count($this->cache) > 64) {
            $this->cache = [];
        }
        $this->cache[$source] = $bytes;

        return $bytes;
    }

    /** Average colour of the image as a #RRGGBB hex, via a 1×1 downsample. */
    private function dominantColor(string $bytes): string
    {
        try {
            $pixel = Image::make($bytes)->resize(1, 1);
            $rgb = $pixel->pickColor(0, 0, 'array');

            return sprintf('#%02x%02x%02x', (int) ($rgb[0] ?? 0), (int) ($rgb[1] ?? 0), (int) ($rgb[2] ?? 0));
        } catch (Throwable) {
            return '#cccccc';
        }
    }
}

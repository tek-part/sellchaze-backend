<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Store;
use App\Services\Storefront\ProductMediaPipeline;
use Illuminate\Console\Command;

/**
 * Downloads the Demo Supplier catalog's remote (royalty-free) product images and stores them locally
 * (optimised JPEG + WebP + thumbnail + metadata) so the storefront is fully offline-safe. Resumable
 * and idempotent — media rows whose path is already a local disk path are skipped, so it can be run
 * repeatedly / in batches (`--limit`) until the whole catalog is localised.
 *
 *   php artisan demo:localise-media --limit=500
 */
class LocaliseDemoSupplierMedia extends Command
{
    protected $signature = 'demo:localise-media {--store=2 : Store id} {--limit=0 : Max media rows to process (0 = all)}';

    protected $description = 'Download + optimise the Demo Supplier catalog images into local storage (offline-safe).';

    public function handle(ProductMediaPipeline $pipeline): int
    {
        $storeId = (int) $this->option('store');
        $limit = (int) $this->option('limit');
        $store = Store::find($storeId);
        if (! $store) {
            $this->error("Store #$storeId not found.");

            return self::FAILURE;
        }

        $query = ProductMedia::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('path', 'like', 'http%')
            ->orderBy('id');
        $total = (clone $query)->count();
        if ($limit > 0) {
            $query->limit($limit);
        }
        $this->info("Localising media for store #$storeId — {$total} remote asset(s) remaining".($limit ? " (this run: up to $limit)" : '').'.');

        $done = 0;
        $failed = 0;
        $productCovers = [];

        foreach ($query->cursor() as $media) {
            $dir = "demo-supplier/products/{$media->store_product_id}";
            $meta = $pipeline->store($media->path, $dir, "m{$media->id}");
            if ($meta === null) {
                $failed++;

                continue;
            }
            $media->forceFill([
                'path' => $meta['path'],
                'webp_path' => $meta['webp_path'],
                'thumbnail_path' => $meta['thumbnail_path'],
                'width' => $meta['width'],
                'height' => $meta['height'],
                'dominant_color' => $meta['dominant_color'],
                'size' => $meta['size'],
                'mime' => $meta['mime'],
            ])->save();

            // Repoint the product's cover image at the local file.
            if ($media->type === 'cover' && ! isset($productCovers[$media->store_product_id])) {
                $productCovers[$media->store_product_id] = $meta['path'];
            }

            $done++;
            if ($done % 50 === 0) {
                $this->line("  …$done localised");
            }
        }

        // Repoint each product's cover image column at its localised cover file.
        foreach ($productCovers as $productId => $path) {
            Product::withoutGlobalScopes()->where('id', $productId)->update(['image' => $path]);
        }

        $this->info("Done. Localised: $done · failed: $failed · remaining: ".max(0, $total - $done - $failed).'.');

        return self::SUCCESS;
    }
}

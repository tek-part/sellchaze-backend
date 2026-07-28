<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Scopes\StoreScope;
use Illuminate\Database\Seeder;

/**
 * Backfills the per-product PDP content the storefront product page renders (highlights, a
 * specifications map, a "Shipping & returns" note, care instructions) for products that have
 * none yet, so the newly-wired theme tabs have something to show. Idempotent: only fills blanks.
 */
class ProductPdpContentSeeder extends Seeder
{
    public function run(): void
    {
        Product::query()->withoutGlobalScope(StoreScope::class)->chunkById(200, function ($products) {
            foreach ($products as $product) {
                $dirty = false;

                if (empty($product->highlights)) {
                    $product->highlights = [
                        'Premium, carefully sourced materials',
                        'Quality-checked before every dispatch',
                        'Backed by our satisfaction guarantee',
                    ];
                    $dirty = true;
                }

                if (empty($product->specifications)) {
                    $product->specifications = [
                        'Brand' => $product->manufacturer ?: 'Sellchase',
                        'Model' => $product->sku ?: ('SC-'.$product->id),
                        'Condition' => 'New',
                    ];
                    $dirty = true;
                }

                if (blank($product->shipping_returns)) {
                    $product->shipping_returns = "Free standard shipping on orders over 500 EGP. Orders ship within 24–48 hours and typically arrive in 2–5 business days.\n\nNot the right fit? Return unused items in their original packaging within 14 days for a full refund.";
                    $dirty = true;
                }

                if (blank($product->care_instructions)) {
                    $product->care_instructions = 'Store in a cool, dry place away from direct sunlight. Wipe clean with a soft, dry cloth.';
                    $dirty = true;
                }

                if ($dirty) {
                    $product->saveQuietly();
                }
            }
        });
    }
}

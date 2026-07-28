<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Models\StoreBrand;
use App\Models\StoreCollection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Backfills the merchandising data the storefront themes need but which was never populated:
 * brands (+ product links), product merchandising flags (bestseller/new/trending/sale),
 * collections (with products attached), and active coupons. Runs per store, idempotent.
 */
class StorefrontMerchandisingSeeder extends Seeder
{
    public function run(): void
    {
        Store::query()->each(function (Store $store) {
            $ownerId = $store->owner_user_id;
            if (! $ownerId) {
                return;
            }

            $products = Product::withoutGlobalScope(StoreScope::class)
                ->where('user_id', $ownerId)
                ->whereNull('store_id')
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            if ($products->isEmpty()) {
                return;
            }

            $this->seedPricingAndStock($products);
            $this->seedBrands($store, $products);
            $this->seedFlags($products);
            $this->seedCollections($store, $products);
            $this->seedCoupons($store);
        });
    }

    /** Ensure demo products are buyable: a non-zero price and stock so the storefront isn't all "Sold out". */
    private function seedPricingAndStock($products): void
    {
        foreach ($products as $p) {
            $dirty = false;
            if ((float) $p->price <= 0) {
                $p->price = mt_rand(80, 600) + 0.99;
                $dirty = true;
            }
            if ((int) $p->stock_quantity <= 0) {
                $p->stock_quantity = mt_rand(15, 120);
                $dirty = true;
            }
            if ($dirty) {
                $p->saveQuietly();
            }
        }
    }

    private function seedBrands(Store $store, $products): void
    {
        $names = ['Aurora', 'Northwind', 'Vanta', 'Apex Labs', 'Meridian'];
        $brands = collect($names)->map(function (string $name, int $i) use ($store) {
            return StoreBrand::withoutGlobalScope(StoreScope::class)->firstOrCreate(
                ['store_id' => $store->id, 'slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => "منتجات {$name} — جودة موثوقة.",
                    'website' => 'https://example.com/'.Str::slug($name),
                    'origin_country' => 'EG',
                    'is_active' => true,
                    'is_featured' => $i < 3,
                    'position' => $i,
                ],
            );
        });

        // Link products round-robin to brands so brand pages/counts are non-empty.
        $products->values()->each(function (Product $p, int $i) use ($brands) {
            if ($p->store_brand_id) {
                return;
            }
            $p->store_brand_id = $brands[$i % $brands->count()]->id;
            $p->saveQuietly();
        });
    }

    private function seedFlags($products): void
    {
        $chunks = $products->chunk((int) ceil($products->count() / 4));
        $apply = function ($set, string $flag) {
            foreach ($set as $p) {
                if (! $p->{$flag}) {
                    $p->{$flag} = true;
                    $p->saveQuietly();
                }
            }
        };
        $apply($chunks->get(0) ?? collect(), 'is_bestseller');
        $apply($chunks->get(1) ?? collect(), 'is_new_arrival');
        $apply($chunks->get(2) ?? collect(), 'is_trending');
        // On-sale: give the last chunk a compare_price above price when missing.
        foreach ($chunks->get(3) ?? collect() as $p) {
            if ((float) $p->compare_price <= (float) $p->price) {
                $p->compare_price = round((float) $p->price * 1.25, 2);
                $p->discount_percent = 20;
                $p->saveQuietly();
            }
        }
    }

    private function seedCollections(Store $store, $products): void
    {
        $defs = [
            ['name' => 'مختارات المتجر', 'slug' => 'featured', 'type' => 'featured', 'flag' => 'is_featured'],
            ['name' => 'وصل حديثًا', 'slug' => 'new-arrivals', 'type' => 'new-arrivals', 'flag' => 'is_new_arrival'],
            ['name' => 'الأكثر مبيعًا', 'slug' => 'best-sellers', 'type' => 'best-sellers', 'flag' => 'is_bestseller'],
            ['name' => 'رائج الآن', 'slug' => 'trending', 'type' => 'trending', 'flag' => 'is_trending'],
        ];

        foreach ($defs as $pos => $def) {
            $collection = StoreCollection::withoutGlobalScope(StoreScope::class)->firstOrCreate(
                ['store_id' => $store->id, 'slug' => $def['slug']],
                [
                    'name' => $def['name'],
                    'type' => $def['type'],
                    'description' => $def['name'],
                    'is_active' => true,
                    'is_automated' => true,
                    'position' => $pos,
                ],
            );

            // Attach the matching flagged products (fallback to first 8 so no collection is empty).
            $members = $products->filter(fn (Product $p) => (bool) $p->{$def['flag']})->take(12);
            if ($members->isEmpty()) {
                $members = $products->take(8);
            }
            $sync = [];
            foreach ($members->values() as $i => $p) {
                $sync[$p->id] = ['position' => $i, 'store_id' => $store->id];
            }
            $collection->products()->syncWithoutDetaching($sync);
        }
    }

    private function seedCoupons(Store $store): void
    {
        $coupons = [
            ['code' => 'WELCOME10', 'type' => 'percentage', 'value' => 10, 'minimum_order_amount' => 0],
            ['code' => 'SAVE50', 'type' => 'fixed', 'value' => 50, 'minimum_order_amount' => 500],
        ];
        foreach ($coupons as $c) {
            Coupon::withoutGlobalScope(StoreScope::class)->firstOrCreate(
                ['store_id' => $store->id, 'code' => $c['code']],
                [
                    'type' => $c['type'],
                    'value' => $c['value'],
                    'minimum_order_amount' => $c['minimum_order_amount'],
                    'starts_at' => now()->subDay(),
                    'expires_at' => now()->addMonth(),
                    'is_active' => true,
                ],
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\StoreBrand;
use App\Models\StoreCollection;
use App\Models\StoreCustomer;
use App\Models\StoreProductMedia;
use App\Models\StoreProductVariant;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\DemoSupplier\CatalogData;
use Database\Seeders\DemoSupplier\ProductGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo Supplier flagship store seeder — turns the supplier account (supplier@demo.com) into a complete,
 * production-quality enterprise storefront: brands, a deep category tree, hundreds of richly-detailed
 * bilingual (EN primary + AR translations) products with variants, media, specifications, rich content,
 * SEO and reviews, plus merchandising collections. Idempotent (clears prior demo catalog first).
 *
 * Scale is configurable via DEMO_SUPPLIER_PPS (products per subcategory). Images are assigned as
 * royalty-free remote URLs that render immediately and are localised offline by `demo:localize-media`.
 */
class DemoSupplierStoreSeeder extends Seeder
{
    private int $productSeq = 0;

    /** @var array<int,int> reviewer StoreCustomer ids */
    private array $reviewerIds = [];

    public function run(): void
    {
        $supplier = User::where('email', 'supplier@demo.com')->first();
        if (! $supplier) {
            $this->command->warn('Supplier (supplier@demo.com) missing — run UsersSeeder first.');

            return;
        }

        $store = Store::query()->where('owner_user_id', $supplier->id)->first();
        if (! $store) {
            $store = Store::create([
                'owner_user_id' => $supplier->id,
                'owner_type' => 'supplier',
                'name' => 'Demo Supplier Marketplace',
                'slug' => 'demo-suppliers-store',
                'currency' => 'SAR',
                'status' => 'active',
            ]);
        }
        $store->forceFill([
            'name' => 'Demo Supplier Marketplace',
            'description' => 'The flagship demonstration storefront — a full enterprise catalog across electronics, home, fashion, beauty and more, in English and Arabic.',
            'currency' => 'SAR',
            'email' => 'hello@demosupplier.example',
            'phone' => '+966 11 234 5678',
            'owner_type' => 'supplier',
            'status' => 'active',
        ])->save();

        app(CurrentStore::class)->set($store);
        $this->clearCatalog($store->id);

        $this->command->info("Seeding flagship catalog for store #{$store->id} ({$store->name})…");

        $this->reviewerIds = $this->seedReviewers($store->id);
        $brands = $this->seedBrands($store->id);
        $subcategories = $this->seedCategories($store->id);
        $this->seedProducts($store->id, $subcategories, $brands);
        $this->seedCollections($store->id);

        $counts = [
            'brands' => count($brands),
            'categories' => Category::withoutGlobalScopes()->where('store_id', $store->id)->count(),
            'products' => Product::withoutGlobalScopes()->where('store_id', $store->id)->count(),
            'variants' => StoreProductVariant::withoutGlobalScopes()->where('store_id', $store->id)->count(),
            'media' => StoreProductMedia::withoutGlobalScopes()->where('store_id', $store->id)->count(),
            'reviews' => ProductReview::withoutGlobalScopes()->where('store_id', $store->id)->count(),
            'collections' => StoreCollection::withoutGlobalScopes()->where('store_id', $store->id)->count(),
        ];
        $this->command->info('Done: '.json_encode($counts));
    }

    private function clearCatalog(int $storeId): void
    {
        foreach ([
            'store_collection_product', 'store_collections', 'store_product_media',
            'product_reviews', 'store_product_variants', 'products',
            'store_brands',
        ] as $table) {
            DB::table($table)->where('store_id', $storeId)->delete();
        }
        // Categories: children first (FK), then parents.
        DB::table('categories')->where('store_id', $storeId)->whereNotNull('parent_id')->delete();
        DB::table('categories')->where('store_id', $storeId)->delete();
    }

    /** @return array<int,int> reviewer customer ids */
    private function seedReviewers(int $storeId): array
    {
        $names = [
            'Omar Al-Harbi', 'Layla Hassan', 'Sara Mansour', 'Khalid Rashid', 'Nora Saleh', 'Yousef Tariq',
            'Huda Kamal', 'Faisal Badr', 'Maryam Zaid', 'Tariq Nabil', 'James Parker', 'Emma Wright',
            'Daniel King', 'Sophia Lewis', 'Noah Roberts', 'Aisha Farouk', 'Mohammed Salem', 'Lina Darwish',
            'Adam Green', 'Reem Qasim', 'Hana Yusuf', 'Bilal Aziz', 'Grace Turner', 'Owen Scott',
            'Fatima Noor', 'Zaid Kareem', 'Chloe Adams', 'Ethan Hughes', 'Mona Fadel', 'Rami Habib',
            'Salma Idris', 'Hassan Amin', 'Olivia Bell', 'Liam Ward', 'Dana Sultan', 'Nasser Ali',
            'Yara Samir', 'Kareem Fouad', 'Isla Morgan', 'Jad Antoun',
        ];
        $ids = [];
        foreach ($names as $i => $name) {
            $slug = Str::slug($name);
            $customer = StoreCustomer::withoutGlobalScopes()->firstOrCreate(
                ['store_id' => $storeId, 'email' => "reviewer.$slug@demosupplier.example"],
                ['name' => $name, 'password' => bcrypt(Str::random(16)), 'is_active' => true, 'email_verified_at' => now()],
            );
            $ids[] = $customer->id;
        }

        return $ids;
    }

    /** @return array<int,StoreBrand> */
    private function seedBrands(int $storeId): array
    {
        $out = [];
        foreach (CatalogData::brands() as $i => $b) {
            $out[] = StoreBrand::create([
                'store_id' => $storeId,
                'name' => $b['en'],
                'slug' => Str::slug($b['en']).'-'.($i + 1),
                'description' => "{$b['en']} is a trusted maker known for quality craftsmanship and dependable performance, based in {$b['country']}.",
                'origin_country' => $b['country'],
                'is_active' => true,
                'is_featured' => $i < 8,
                'position' => $i,
                'seo_title' => "{$b['en']} products",
                'seo_description' => "Shop {$b['en']} — quality {$b['country']} craftsmanship across the catalog.",
                'translations' => ['ar' => ['name' => $b['ar'], 'description' => "{$b['ar']} علامة موثوقة معروفة بالجودة والأداء، من {$b['country']}."]],
            ]);
        }

        return $out;
    }

    /** @return array<int,array{model:Category,kind:string,en:string,ar:string}> flattened subcategories */
    private function seedCategories(int $storeId): array
    {
        $subs = [];
        foreach (CatalogData::categories() as $ci => $cat) {
            $parent = Category::create([
                'store_id' => $storeId,
                'parent_id' => null,
                'name' => $cat['en'],
                'slug' => Str::slug($cat['en']),
                'description' => "Explore our {$cat['en']} range — curated quality across trusted brands.",
                'icon' => $cat['icon'],
                'is_active' => true,
                'is_featured' => $ci < 8,
                'position' => $ci,
                'image' => $this->categoryImage($cat['en'], $ci),
                'seo_title' => "{$cat['en']} — Demo Supplier Marketplace",
                'seo_description' => "Shop {$cat['en']} online. Trusted brands, real specs, fast shipping and easy returns.",
                'translations' => ['ar' => ['name' => $cat['ar'], 'description' => "استكشف تشكيلة {$cat['ar']} — جودة منتقاة من علامات موثوقة."]],
            ]);

            foreach ($cat['subs'] as $si => $sub) {
                $child = Category::create([
                    'store_id' => $storeId,
                    'parent_id' => $parent->id,
                    'name' => $sub['en'],
                    'slug' => Str::slug($cat['en'].'-'.$sub['en']),
                    'description' => "Quality {$sub['en']} in {$cat['en']} — chosen for durability, value and style.",
                    'is_active' => true,
                    'position' => $si,
                    'image' => $this->categoryImage($sub['en'], $ci * 10 + $si + 100),
                    'seo_title' => "{$sub['en']} | {$cat['en']}",
                    'seo_description' => "Browse {$sub['en']} — real specifications, verified reviews and dependable delivery.",
                    'translations' => ['ar' => ['name' => $sub['ar'], 'description' => "{$sub['ar']} ضمن {$cat['ar']} بجودة عالية وقيمة ممتازة."]],
                ]);
                $subs[] = ['model' => $child, 'kind' => $cat['kind'], 'en' => $sub['en'], 'ar' => $sub['ar']];
            }
        }

        return $subs;
    }

    /** @param  array<int,array{model:Category,kind:string,en:string,ar:string}>  $subs  @param  array<int,StoreBrand>  $brands */
    private function seedProducts(int $storeId, array $subs, array $brands): void
    {
        $gen = new ProductGenerator('SAR');
        $perSub = (int) (getenv('DEMO_SUPPLIER_PPS') ?: 8);
        $brandCount = count($brands);

        foreach ($subs as $sub) {
            for ($i = 0; $i < $perSub; $i++) {
                $this->productSeq++;
                $brand = $brands[$this->productSeq % $brandCount];
                $spec = $gen->make(
                    ['en' => $sub['en'], 'ar' => $sub['ar'], 'kind' => $sub['kind']],
                    ['en' => $brand->name, 'ar' => $brand->translations['ar']['name'] ?? $brand->name, 'country' => $brand->origin_country ?? ''],
                    $this->productSeq,
                );
                $this->persistProduct($storeId, $sub['model']->id, $brand->id, $spec);
            }
        }
    }

    /** @param  array<string,mixed>  $spec */
    private function persistProduct(int $storeId, int $categoryId, int $brandId, array $spec): void
    {
        $cover = $this->productImage($spec['sku'], 0);
        $product = Product::create([
            'store_id' => $storeId,
            'category_id' => $categoryId,
            'store_brand_id' => $brandId,
            'name' => $spec['name'],
            'slug' => $spec['slug'].'-'.$this->productSeq,
            'sku' => $spec['sku'],
            'barcode' => $spec['barcode'],
            'supplier_code' => $spec['supplier_code'],
            'description' => $spec['description'],
            'short_description' => $spec['short_description'],
            'long_description' => $spec['long_description'],
            'keywords' => $spec['keywords'],
            'tags' => $spec['tags'],
            'specifications' => $spec['specifications'],
            'content' => $spec['content'],
            'dimensions' => $spec['dimensions'],
            'weight' => $spec['weight'],
            'material' => $spec['material'],
            'warranty' => $spec['warranty'],
            'origin_country' => $spec['origin_country'],
            'manufacturer' => $spec['manufacturer'],
            'unit' => $spec['unit'],
            'currency' => $spec['currency'],
            'price' => $spec['price'],
            'compare_price' => $spec['compare_price'],
            'cost' => $spec['cost'],
            'vat_rate' => $spec['vat_rate'],
            'discount_percent' => $spec['discount_percent'],
            'stock_quantity' => $spec['stock_quantity'],
            'reserved_quantity' => $spec['reserved_quantity'],
            'reorder_level' => $spec['reorder_level'],
            'image' => $cover,
            'is_active' => true,
            'is_featured' => $spec['flags']['is_featured'],
            'is_bestseller' => $spec['flags']['is_bestseller'],
            'is_new_arrival' => $spec['flags']['is_new_arrival'],
            'is_trending' => $spec['flags']['is_trending'],
            'rating_avg' => $spec['rating_avg'],
            'rating_count' => count($spec['reviews']),
            'sales_count' => $spec['sales_count'],
            'views_count' => $spec['views_count'],
            'seo_title' => $spec['seo_title'],
            'seo_description' => $spec['seo_description'],
            'translations' => ['ar' => [
                'name' => $spec['name_ar'],
                'short_description' => $spec['short_description_ar'],
                'description' => $spec['description_ar'],
            ]],
            'published_at' => now()->subDays($this->productSeq % 300),
            'position' => $this->productSeq,
        ]);

        // Media: cover + gallery + thumbnail/zoom/hover records (remote URLs, localised later).
        $mediaRows = [];
        $types = ['cover', 'gallery', 'gallery', 'gallery', 'gallery', 'thumbnail', 'zoom', 'hover'];
        $galleryCount = 4 + ($this->productSeq % 4); // 4–7 gallery + cover
        $slots = array_merge(['cover'], array_fill(0, $galleryCount, 'gallery'), ['thumbnail', 'zoom', 'hover']);
        foreach ($slots as $pos => $type) {
            $mediaRows[] = [
                'store_id' => $storeId,
                'store_product_id' => $product->id,
                'type' => $type,
                'disk' => 'public',
                'path' => $this->productImage($spec['sku'], $pos),
                'alt' => "{$spec['name']} — {$type} image",
                'position' => $pos,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        StoreProductMedia::insert($mediaRows);

        // Variants.
        $variantRows = [];
        foreach ($spec['variants'] as $pos => $v) {
            $variantRows[] = [
                'store_id' => $storeId,
                'store_product_id' => $product->id,
                'name' => $v['name'],
                'sku' => $v['sku'],
                'barcode' => $v['barcode'],
                'price_override' => $v['price_override'],
                'compare_price' => $v['compare_price'],
                'cost' => $v['cost'],
                'stock_quantity' => $v['stock_quantity'],
                'options' => json_encode($v['options']),
                'image' => $this->productImage($spec['sku'], $pos + 1),
                'is_active' => true,
                'position' => $v['position'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        StoreProductVariant::insert($variantRows);

        // Reviews.
        $reviewRows = [];
        $reviewerCount = count($this->reviewerIds);
        foreach ($spec['reviews'] as $ri => $r) {
            $reviewRows[] = [
                'store_id' => $storeId,
                'store_customer_id' => $this->reviewerIds[($product->id + $ri) % $reviewerCount],
                'store_product_id' => $product->id,
                'author_name' => $r['author_name'],
                'rating' => $r['rating'],
                'title' => $r['title'],
                'body' => $r['body'],
                'pros' => json_encode($r['pros']),
                'cons' => json_encode($r['cons']),
                'is_verified' => $r['is_verified'],
                'helpful_count' => $r['helpful_count'],
                'status' => 'approved',
                'reviewed_at' => now()->subDays($r['days_ago']),
                'created_at' => now()->subDays($r['days_ago']),
                'updated_at' => now()->subDays($r['days_ago']),
            ];
        }
        ProductReview::insert($reviewRows);
    }

    private function seedCollections(int $storeId): void
    {
        $flagFor = [
            'featured' => 'is_featured', 'new-arrivals' => 'is_new_arrival',
            'best-sellers' => 'is_bestseller', 'trending' => 'is_trending',
        ];
        foreach (CatalogData::collections() as $i => $c) {
            $collection = StoreCollection::create([
                'store_id' => $storeId,
                'name' => $c['en'],
                'slug' => Str::slug($c['en']),
                'type' => $c['type'],
                'description' => "{$c['en']} — a hand-picked edit from across the catalog.",
                'image' => $this->categoryImage($c['en'], 900 + $i),
                'is_active' => true,
                'is_automated' => isset($flagFor[$c['type']]),
                'position' => $i,
                'seo_title' => "{$c['en']} | Demo Supplier Marketplace",
                'seo_description' => "Discover the {$c['en']} collection — curated quality across trusted brands.",
                'translations' => ['ar' => ['name' => $c['ar'], 'description' => "{$c['ar']} — تشكيلة منتقاة من الكتالوج."]],
            ]);

            $query = Product::withoutGlobalScopes()->where('store_id', $storeId);
            if (isset($flagFor[$c['type']])) {
                $query->where($flagFor[$c['type']], true);
            } elseif ($c['type'] === 'weekly-deals') {
                $query->where('discount_percent', '>', 0)->orderByDesc('discount_percent');
            } elseif ($c['type'] === 'luxury') {
                $query->orderByDesc('price');
            } elseif ($c['type'] === 'budget') {
                $query->orderBy('price');
            } else { // staff-picks / seasonal / manual
                $query->orderByDesc('rating_avg');
            }
            $ids = $query->limit(40)->pluck('id')->all();
            $attach = [];
            foreach ($ids as $pos => $pid) {
                $attach[$pid] = ['store_id' => $storeId, 'position' => $pos];
            }
            if ($attach) {
                $collection->products()->attach($attach);
            }
        }
    }

    private function productImage(string $sku, int $index): string
    {
        // Royalty-free, deterministic per (sku,index) — unique across the catalog. Localised offline
        // by `demo:localize-media`; renders immediately via the http-passthrough imageUrl() helpers.
        $seed = strtolower($sku).'-'.$index;

        return "https://picsum.photos/seed/{$seed}/900/1100";
    }

    private function categoryImage(string $name, int $index): string
    {
        $seed = Str::slug($name).'-cat-'.$index;

        return "https://picsum.photos/seed/{$seed}/1200/700";
    }
}

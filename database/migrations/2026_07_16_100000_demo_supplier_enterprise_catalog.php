<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Add an index, ignoring "already exists" so the migration is safely re-runnable after a partial apply. */
if (! function_exists('demo_supplier_add_index')) {
    function demo_supplier_add_index(string $tableName, array $columns): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                $table->index($columns);
            });
        } catch (\Throwable $e) {
            // duplicate index — already present from a prior partial run
        }
    }
}

/**
 * Enterprise-catalog schema extensions for the storefront (additive + backward-compatible).
 *
 * Adds real e-commerce depth the lean storefront catalog lacked — category hierarchy, brands,
 * collections, product PIM fields (specs/dimensions/inventory/pricing/SEO/flags/rich content),
 * variant-level inventory + imagery, a first-class product media table, and richer reviews — plus an
 * additive `translations` JSON on the translatable entities for AR/EN.
 *
 * Every change is additive: new tables, or nullable columns guarded by hasColumn(). No existing column
 * is altered or dropped, so existing data, APIs and the frozen theme data-contract keep working. New
 * fields are surfaced additively by the API resources (extra keys the frontend mappers ignore).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- categories: hierarchy + SEO
        Schema::table('store_categories', function (Blueprint $table): void {
            if (! Schema::hasColumn('store_categories', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()->after('store_id')
                    ->constrained('store_categories')->nullOnDelete();
            }
            if (! Schema::hasColumn('store_categories', 'icon')) {
                $table->string('icon')->nullable()->after('image');
            }
            if (! Schema::hasColumn('store_categories', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('store_categories', 'seo_title')) {
                $table->string('seo_title')->nullable();
                $table->string('seo_description', 512)->nullable();
            }
            if (! Schema::hasColumn('store_categories', 'translations')) {
                $table->json('translations')->nullable();
            }
        });
        demo_supplier_add_index('store_categories', ['store_id', 'parent_id']);

        // ---------------------------------------------------------------- brands
        if (! Schema::hasTable('store_brands')) {
            Schema::create('store_brands', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->text('description')->nullable();
                $table->string('logo')->nullable();
                $table->string('website')->nullable();
                $table->string('origin_country')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_featured')->default(false);
                $table->unsignedInteger('position')->default(0);
                $table->string('seo_title')->nullable();
                $table->string('seo_description', 512)->nullable();
                $table->json('translations')->nullable();
                $table->timestamps();

                $table->unique(['store_id', 'slug']);
                $table->index(['store_id', 'is_active']);
                $table->index(['store_id', 'is_featured']);
            });
        }

        // ---------------------------------------------------------------- products: PIM depth
        Schema::table('store_products', function (Blueprint $table): void {
            $add = static function (string $col, callable $def): void {
                if (! Schema::hasColumn('store_products', $col)) {
                    $def();
                }
            };

            $add('store_brand_id', fn () => $table->foreignId('store_brand_id')->nullable()->after('store_category_id')->constrained('store_brands')->nullOnDelete());
            $add('long_description', fn () => $table->longText('long_description')->nullable()->after('description'));
            $add('supplier_code', fn () => $table->string('supplier_code')->nullable()->after('barcode'));
            $add('keywords', fn () => $table->text('keywords')->nullable());
            $add('tags', fn () => $table->json('tags')->nullable());
            $add('specifications', fn () => $table->json('specifications')->nullable());
            $add('attributes', fn () => $table->json('attributes')->nullable());
            $add('content', fn () => $table->json('content')->nullable()); // benefits/features/whats_included/how_to_use/warnings/shipping/returns/faqs
            $add('dimensions', fn () => $table->json('dimensions')->nullable());
            $add('weight', fn () => $table->decimal('weight', 10, 3)->nullable());
            $add('material', fn () => $table->string('material')->nullable());
            $add('warranty', fn () => $table->string('warranty')->nullable());
            $add('origin_country', fn () => $table->string('origin_country')->nullable());
            $add('manufacturer', fn () => $table->string('manufacturer')->nullable());
            $add('unit', fn () => $table->string('unit')->nullable());
            $add('currency', fn () => $table->string('currency', 8)->nullable());
            $add('cost', fn () => $table->decimal('cost', 12, 2)->nullable());
            $add('vat_rate', fn () => $table->decimal('vat_rate', 5, 2)->nullable());
            $add('discount_percent', fn () => $table->decimal('discount_percent', 5, 2)->nullable());
            $add('stock_quantity', fn () => $table->integer('stock_quantity')->default(0));
            $add('reserved_quantity', fn () => $table->integer('reserved_quantity')->default(0));
            $add('reorder_level', fn () => $table->integer('reorder_level')->default(0));
            $add('is_bestseller', fn () => $table->boolean('is_bestseller')->default(false));
            $add('is_new_arrival', fn () => $table->boolean('is_new_arrival')->default(false));
            $add('is_trending', fn () => $table->boolean('is_trending')->default(false));
            $add('rating_avg', fn () => $table->decimal('rating_avg', 3, 2)->default(0));
            $add('rating_count', fn () => $table->unsignedInteger('rating_count')->default(0));
            $add('sales_count', fn () => $table->unsignedInteger('sales_count')->default(0));
            $add('views_count', fn () => $table->unsignedInteger('views_count')->default(0));
            $add('seo_title', fn () => $table->string('seo_title')->nullable());
            $add('seo_description', fn () => $table->string('seo_description', 512)->nullable());
            $add('translations', fn () => $table->json('translations')->nullable());
            $add('published_at', fn () => $table->timestamp('published_at')->nullable());
        });
        demo_supplier_add_index('store_products', ['store_id', 'is_bestseller']);
        demo_supplier_add_index('store_products', ['store_id', 'is_new_arrival']);
        demo_supplier_add_index('store_products', ['store_id', 'is_trending']);
        demo_supplier_add_index('store_products', ['store_id', 'store_brand_id']);
        demo_supplier_add_index('store_products', ['store_id', 'rating_avg']);

        // ---------------------------------------------------------------- variants: inventory + imagery
        Schema::table('store_product_variants', function (Blueprint $table): void {
            $add = static function (string $col, callable $def): void {
                if (! Schema::hasColumn('store_product_variants', $col)) {
                    $def();
                }
            };
            $add('compare_price', fn () => $table->decimal('compare_price', 12, 2)->nullable()->after('price_override'));
            $add('cost', fn () => $table->decimal('cost', 12, 2)->nullable());
            $add('stock_quantity', fn () => $table->integer('stock_quantity')->default(0));
            $add('reserved_quantity', fn () => $table->integer('reserved_quantity')->default(0));
            $add('image', fn () => $table->string('image')->nullable());
            $add('translations', fn () => $table->json('translations')->nullable());
        });

        // ---------------------------------------------------------------- product media
        if (! Schema::hasTable('store_product_media')) {
            Schema::create('store_product_media', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->foreignId('store_product_id')->constrained('store_products')->cascadeOnDelete();
                $table->foreignId('store_product_variant_id')->nullable()->constrained('store_product_variants')->nullOnDelete();
                $table->string('type')->default('gallery'); // cover | gallery | thumbnail | zoom | hover
                $table->string('disk')->default('public');
                $table->string('path');
                $table->string('webp_path')->nullable();
                $table->string('thumbnail_path')->nullable();
                $table->string('alt')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->string('dominant_color', 16)->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('mime')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->index(['store_product_id', 'type']);
                $table->index(['store_id', 'store_product_id']);
            });
        }

        // ---------------------------------------------------------------- collections
        if (! Schema::hasTable('store_collections')) {
            Schema::create('store_collections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->string('type')->default('manual'); // featured|new-arrivals|best-sellers|trending|weekly-deals|luxury|budget|staff-picks|seasonal|manual
                $table->text('description')->nullable();
                $table->string('image')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_automated')->default(false);
                $table->unsignedInteger('position')->default(0);
                $table->string('seo_title')->nullable();
                $table->string('seo_description', 512)->nullable();
                $table->json('translations')->nullable();
                $table->timestamps();

                $table->unique(['store_id', 'slug']);
                $table->index(['store_id', 'is_active']);
                $table->index(['store_id', 'type']);
            });
        }
        if (! Schema::hasTable('store_collection_product')) {
            Schema::create('store_collection_product', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->foreignId('store_collection_id')->constrained('store_collections')->cascadeOnDelete();
                $table->foreignId('store_product_id')->constrained('store_products')->cascadeOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['store_collection_id', 'store_product_id'], 'scp_collection_product_unique');
                $table->index(['store_id', 'store_collection_id']);
            });
        }

        // ---------------------------------------------------------------- reviews: pros/cons/verified
        Schema::table('product_reviews', function (Blueprint $table): void {
            $add = static function (string $col, callable $def): void {
                if (! Schema::hasColumn('product_reviews', $col)) {
                    $def();
                }
            };
            $add('author_name', fn () => $table->string('author_name')->nullable());
            $add('pros', fn () => $table->json('pros')->nullable());
            $add('cons', fn () => $table->json('cons')->nullable());
            $add('is_verified', fn () => $table->boolean('is_verified')->default(false));
            $add('helpful_count', fn () => $table->unsignedInteger('helpful_count')->default(0));
            $add('reviewed_at', fn () => $table->timestamp('reviewed_at')->nullable());
        });
    }

    public function down(): void
    {
        // Additive migration — drop only the tables/columns it introduced, guarded so a partial
        // rollback is safe. Existing catalog columns are never touched.
        Schema::dropIfExists('store_collection_product');
        Schema::dropIfExists('store_collections');
        Schema::dropIfExists('store_product_media');

        foreach ([
            'product_reviews' => ['author_name', 'pros', 'cons', 'is_verified', 'helpful_count', 'reviewed_at'],
            'store_product_variants' => ['compare_price', 'cost', 'stock_quantity', 'reserved_quantity', 'image', 'translations'],
        ] as $tableName => $cols) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $cols): void {
                foreach ($cols as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::table('store_products', function (Blueprint $table): void {
            foreach ([
                'store_brand_id', 'long_description', 'supplier_code', 'keywords', 'tags', 'specifications',
                'attributes', 'content', 'dimensions', 'weight', 'material', 'warranty', 'origin_country',
                'manufacturer', 'unit', 'currency', 'cost', 'vat_rate', 'discount_percent', 'stock_quantity',
                'reserved_quantity', 'reorder_level', 'is_bestseller', 'is_new_arrival', 'is_trending',
                'rating_avg', 'rating_count', 'sales_count', 'views_count', 'seo_title', 'seo_description',
                'translations', 'published_at',
            ] as $col) {
                if (Schema::hasColumn('store_products', $col)) {
                    if ($col === 'store_brand_id') {
                        $table->dropConstrainedForeignId('store_brand_id');
                    } else {
                        $table->dropColumn($col);
                    }
                }
            }
        });

        Schema::dropIfExists('store_brands');

        Schema::table('store_categories', function (Blueprint $table): void {
            if (Schema::hasColumn('store_categories', 'parent_id')) {
                $table->dropConstrainedForeignId('parent_id');
            }
            foreach (['icon', 'is_featured', 'seo_title', 'seo_description', 'translations'] as $col) {
                if (Schema::hasColumn('store_categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

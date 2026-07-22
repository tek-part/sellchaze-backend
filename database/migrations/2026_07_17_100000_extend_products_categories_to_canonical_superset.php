<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Canonical Product System.
 *
 * ADDITIVE ONLY. Extends the legacy `products` / `categories` tables into a superset of
 * `store_products` / `store_categories` so `products`/`categories` can become the single canonical
 * catalog system in a later phase. No column is renamed or dropped; no data is migrated; the
 * `store_*` tables and their FKs are left untouched. Every add is guarded by hasColumn(), so the
 * migration is safely re-runnable after a partial apply.
 *
 * Deliberate omissions (compatibility, documented in the Phase 1 report):
 *  - No `store_id` global scope is grafted onto Product here (would break legacy B2B global reads).
 *  - The `attributes` JSON column from store_products is NOT added: the name collides with the
 *    existing Product::attributes() relation and Eloquent's internal $attributes. Deferred to Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $add = static function (string $col, callable $def): void {
                if (! Schema::hasColumn('products', $col)) {
                    $def();
                }
            };

            // tenancy (nullable — B2B rows stay store-less; NO global scope on Product)
            $add('store_id', fn () => $table->foreignId('store_id')->nullable()->after('category_id')->constrained('stores')->nullOnDelete());
            $add('store_brand_id', fn () => $table->foreignId('store_brand_id')->nullable()->after('store_id')->constrained('store_brands')->nullOnDelete());

            // identity / merchandising
            $add('slug', fn () => $table->string('slug')->nullable()->after('name'));
            $add('sku', fn () => $table->string('sku')->nullable()->after('slug'));
            $add('barcode', fn () => $table->string('barcode')->nullable()->after('sku'));
            $add('supplier_code', fn () => $table->string('supplier_code')->nullable()->after('barcode'));
            $add('short_description', fn () => $table->string('short_description', 500)->nullable()->after('description'));
            $add('long_description', fn () => $table->longText('long_description')->nullable()->after('short_description'));
            $add('keywords', fn () => $table->text('keywords')->nullable());

            // structured JSON (note: `attributes` intentionally omitted — see class docblock)
            $add('tags', fn () => $table->json('tags')->nullable());
            $add('specifications', fn () => $table->json('specifications')->nullable());
            $add('content', fn () => $table->json('content')->nullable());
            $add('dimensions', fn () => $table->json('dimensions')->nullable());

            // pricing
            $add('price', fn () => $table->decimal('price', 12, 2)->default(0));
            $add('compare_price', fn () => $table->decimal('compare_price', 12, 2)->nullable());
            $add('cost', fn () => $table->decimal('cost', 12, 2)->nullable());
            $add('vat_rate', fn () => $table->decimal('vat_rate', 5, 2)->nullable());
            $add('discount_percent', fn () => $table->decimal('discount_percent', 5, 2)->nullable());
            $add('currency', fn () => $table->string('currency', 8)->nullable());

            // logistics
            $add('weight', fn () => $table->decimal('weight', 10, 3)->nullable());
            $add('material', fn () => $table->string('material')->nullable());
            $add('warranty', fn () => $table->string('warranty')->nullable());
            $add('origin_country', fn () => $table->string('origin_country')->nullable());
            $add('manufacturer', fn () => $table->string('manufacturer')->nullable());
            $add('unit', fn () => $table->string('unit')->nullable());

            // inventory (denormalised; reconciled with warehouse_inventories in a later phase)
            $add('stock_quantity', fn () => $table->integer('stock_quantity')->default(0));
            $add('reserved_quantity', fn () => $table->integer('reserved_quantity')->default(0));
            $add('reorder_level', fn () => $table->integer('reorder_level')->default(0));

            // visibility / flags
            $add('is_active', fn () => $table->boolean('is_active')->default(true));
            $add('is_featured', fn () => $table->boolean('is_featured')->default(false));
            $add('is_bestseller', fn () => $table->boolean('is_bestseller')->default(false));
            $add('is_new_arrival', fn () => $table->boolean('is_new_arrival')->default(false));
            $add('is_trending', fn () => $table->boolean('is_trending')->default(false));

            // rollups / analytics
            $add('rating_avg', fn () => $table->decimal('rating_avg', 3, 2)->default(0));
            $add('rating_count', fn () => $table->unsignedInteger('rating_count')->default(0));
            $add('sales_count', fn () => $table->unsignedInteger('sales_count')->default(0));
            $add('views_count', fn () => $table->unsignedInteger('views_count')->default(0));

            // SEO / i18n / publishing / ordering
            $add('seo_title', fn () => $table->string('seo_title')->nullable());
            $add('seo_description', fn () => $table->string('seo_description', 512)->nullable());
            $add('translations', fn () => $table->json('translations')->nullable());
            $add('published_at', fn () => $table->timestamp('published_at')->nullable());
            $add('position', fn () => $table->unsignedInteger('position')->default(0));
        });

        $safeIndex = static function (string $tableName, array $cols, bool $unique = false): void {
            try {
                Schema::table($tableName, function (Blueprint $table) use ($cols, $unique): void {
                    $unique ? $table->unique($cols) : $table->index($cols);
                });
            } catch (\Throwable $e) {
                // index already present from a prior partial run
            }
        };
        $safeIndex('products', ['store_id', 'slug'], true);
        $safeIndex('products', ['store_id', 'sku'], true);
        $safeIndex('products', ['store_id', 'is_active']);
        $safeIndex('products', ['store_id', 'is_featured']);
        $safeIndex('products', ['store_id', 'store_brand_id']);

        Schema::table('categories', function (Blueprint $table): void {
            $add = static function (string $col, callable $def): void {
                if (! Schema::hasColumn('categories', $col)) {
                    $def();
                }
            };
            $add('store_id', fn () => $table->foreignId('store_id')->nullable()->after('wigpleasure_category_id')->constrained('stores')->nullOnDelete());
            $add('parent_id', fn () => $table->foreignId('parent_id')->nullable()->after('store_id')->constrained('categories')->nullOnDelete());
            $add('slug', fn () => $table->string('slug')->nullable()->after('name'));
            $add('description', fn () => $table->text('description')->nullable());
            $add('image', fn () => $table->string('image')->nullable());
            $add('icon', fn () => $table->string('icon')->nullable());
            $add('is_active', fn () => $table->boolean('is_active')->default(true));
            $add('is_featured', fn () => $table->boolean('is_featured')->default(false));
            $add('position', fn () => $table->unsignedInteger('position')->default(0));
            $add('seo_title', fn () => $table->string('seo_title')->nullable());
            $add('seo_description', fn () => $table->string('seo_description', 512)->nullable());
            $add('translations', fn () => $table->json('translations')->nullable());
        });
        $safeIndex('categories', ['store_id', 'slug'], true);
        $safeIndex('categories', ['store_id', 'parent_id']);
        $safeIndex('categories', ['store_id', 'is_active']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            foreach (['store_id', 'store_brand_id'] as $fk) {
                if (Schema::hasColumn('products', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach ([
                'slug', 'sku', 'barcode', 'supplier_code', 'short_description', 'long_description', 'keywords',
                'tags', 'specifications', 'content', 'dimensions', 'price', 'compare_price', 'cost', 'vat_rate',
                'discount_percent', 'currency', 'weight', 'material', 'warranty', 'origin_country', 'manufacturer',
                'unit', 'stock_quantity', 'reserved_quantity', 'reorder_level', 'is_active', 'is_featured',
                'is_bestseller', 'is_new_arrival', 'is_trending', 'rating_avg', 'rating_count', 'sales_count',
                'views_count', 'seo_title', 'seo_description', 'translations', 'published_at', 'position',
            ] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('categories', function (Blueprint $table): void {
            foreach (['store_id', 'parent_id'] as $fk) {
                if (Schema::hasColumn('categories', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach ([
                'slug', 'description', 'image', 'icon', 'is_active', 'is_featured', 'position',
                'seo_title', 'seo_description', 'translations',
            ] as $col) {
                if (Schema::hasColumn('categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical product unification — final step.
 *
 * Makes `products`/`categories` the single catalog tables: copies any existing store catalog data into
 * them, repoints every storefront satellite foreign key from `store_products` to `products`, then drops
 * `store_products` and `store_categories`.
 *
 * PRODUCTION-SAFE ID HANDLING (collision-proof).
 * Legacy `products`/`categories` and storefront `store_products`/`store_categories` occupy independent,
 * OVERLAPPING id ranges. To guarantee no legacy row is ever overwritten and no reference is orphaned:
 *   - legacy rows keep their ids unchanged;
 *   - each store row is inserted with id = old_id + OFFSET, where OFFSET = MAX(id) of the destination
 *     table taken BEFORE the copy — so every store id lands strictly above every existing legacy id
 *     (no collision, no overwrite);
 *   - every foreign key that referenced a store id is shifted by the SAME offset, so all relations are
 *     preserved exactly.
 * On a fresh install both tables are empty ⇒ OFFSET = 0 and every step is a no-op (migrate:fresh path).
 */
return new class extends Migration
{
    /** Satellite table => on-delete behaviour of its store_product_id FK. */
    private array $satellites = [
        'cart_items' => 'nullOnDelete',
        'store_order_items' => 'nullOnDelete',
        'wishlist_items' => 'cascadeOnDelete',
        'product_reviews' => 'cascadeOnDelete',
        'store_product_variants' => 'cascadeOnDelete',
        'store_product_media' => 'cascadeOnDelete',
        'store_collection_product' => 'cascadeOnDelete',
    ];

    public function up(): void
    {
        // 1. products.category_id + user_id must allow NULL: storefront products belong to a store and
        //    have no legacy category/owner-user; B2B products have a user and (optionally) a category.
        Schema::table('products', fn (Blueprint $table) => $table->dropForeign(['category_id']));
        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger('category_id')->nullable()->change());
        Schema::table('products', fn (Blueprint $table) => $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete());

        Schema::table('products', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable()->change());
        Schema::table('products', fn (Blueprint $table) => $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete());

        // 2. Copy store data into the canonical tables with a collision-proof id offset.
        $productOffset = 0;

        DB::transaction(function () use (&$productOffset): void {
            // --- categories -------------------------------------------------------------------------
            if (Schema::hasTable('store_categories')) {
                $categoryOffset = (int) DB::table('categories')->max('id');
                $catCols = Schema::getColumnListing('categories');

                foreach (DB::table('store_categories')->orderBy('id')->cursor() as $row) {
                    $data = array_intersect_key((array) $row, array_flip($catCols));
                    $data['id'] = $row->id + $categoryOffset;
                    // self-referential parent shifts by the same offset
                    $data['parent_id'] = isset($row->parent_id) && $row->parent_id !== null
                        ? $row->parent_id + $categoryOffset
                        : null;
                    DB::table('categories')->insert($data);
                }

                // --- products (must run after categories so category_id remaps correctly) -----------
                if (Schema::hasTable('store_products')) {
                    $productOffset = (int) DB::table('products')->max('id');
                    $productCols = Schema::getColumnListing('products');

                    foreach (DB::table('store_products')->orderBy('id')->cursor() as $row) {
                        $data = (array) $row;
                        // store_category_id -> canonical category_id, shifted by the category offset
                        $data['category_id'] = isset($row->store_category_id) && $row->store_category_id !== null
                            ? $row->store_category_id + $categoryOffset
                            : null;
                        $data = array_intersect_key($data, array_flip($productCols));
                        $data['id'] = $row->id + $productOffset;
                        DB::table('products')->insert($data);
                    }
                }
            }
        });

        // 3. Repoint every satellite FK from store_products -> products, shifting its DATA by the same
        //    offset so each row now references the relocated product id.
        foreach ($this->satellites as $tableName => $onDelete) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'store_product_id')) {
                continue;
            }

            // a) drop the old constraint (to store_products) so the data can be shifted
            Schema::table($tableName, function (Blueprint $table): void {
                try {
                    $table->dropForeign(['store_product_id']);
                } catch (Throwable $e) {
                    // FK already absent
                }
            });

            // b) shift referencing ids by the product offset (no-op when offset is 0 / table empty)
            if ($productOffset > 0) {
                DB::table($tableName)
                    ->whereNotNull('store_product_id')
                    ->update(['store_product_id' => DB::raw('store_product_id + '.$productOffset)]);
            }

            // c) add the new constraint (to products)
            Schema::table($tableName, function (Blueprint $table) use ($onDelete): void {
                $constraint = $table->foreign('store_product_id')->references('id')->on('products');
                $onDelete === 'cascadeOnDelete' ? $constraint->cascadeOnDelete() : $constraint->nullOnDelete();
            });
        }

        // 4. Drop the obsolete store tables (their remaining FKs drop with them).
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('store_categories');
    }

    public function down(): void
    {
        throw new RuntimeException('The canonical catalog unification is not reversible via migrate:rollback. Restore from backup.');
    }
};

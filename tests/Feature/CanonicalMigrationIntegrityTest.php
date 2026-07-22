<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the production data-migration path of the canonical unification is COLLISION-SAFE.
 *
 * RefreshDatabase leaves the DB in its post-migration state (products/categories present, store_* dropped).
 * We rebuild a realistic pre-cutover scenario — legacy rows AND store rows sharing the SAME primary keys —
 * then invoke the unify migration's up() and assert that no legacy row is overwritten, every store row is
 * relocated above the legacy max id, and the category FK is remapped consistently.
 */
class CanonicalMigrationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unify_migration_never_overwrites_legacy_rows_on_id_collision(): void
    {
        // --- Arrange: a legacy B2B category + product occupying id = 1 (store_id NULL) --------------
        DB::table('categories')->insert(['id' => 1, 'name' => 'LEGACY-CAT', 'store_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->insert(['id' => 1, 'name' => 'LEGACY-PROD', 'store_id' => null, 'category_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // --- Recreate the store tables with data that COLLIDES on id = 1 ----------------------------
        Schema::create('store_categories', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('store_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->timestamps();
        });
        Schema::create('store_products', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('store_id')->nullable();
            $t->unsignedBigInteger('store_category_id')->nullable();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->decimal('price', 12, 2)->default(0);
            $t->timestamps();
        });
        DB::table('store_categories')->insert(['id' => 1, 'store_id' => null, 'parent_id' => null, 'name' => 'STORE-CAT', 'slug' => 'store-cat', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('store_products')->insert(['id' => 1, 'store_id' => null, 'store_category_id' => 1, 'name' => 'STORE-PROD', 'slug' => 'store-prod', 'price' => 9.99, 'created_at' => now(), 'updated_at' => now()]);

        // --- Act: run the actual unify migration ----------------------------------------------------
        $migration = require database_path('migrations/2026_07_18_100000_unify_catalog_onto_products_and_drop_store_tables.php');
        $migration->up();

        // --- Assert: legacy rows are UNTOUCHED (no overwrite) ---------------------------------------
        $this->assertSame('LEGACY-PROD', DB::table('products')->where('id', 1)->value('name'), 'legacy product 1 must not be overwritten');
        $this->assertSame('LEGACY-CAT', DB::table('categories')->where('id', 1)->value('name'), 'legacy category 1 must not be overwritten');

        // --- Assert: store rows were relocated above the legacy max id (offset = 1) -----------------
        $this->assertSame('STORE-PROD', DB::table('products')->where('id', 2)->value('name'), 'store product must land at id = old + offset');
        $this->assertSame('STORE-CAT', DB::table('categories')->where('id', 2)->value('name'), 'store category must land at id = old + offset');

        // --- Assert: the store product's category FK was remapped to the relocated category ---------
        $this->assertSame(2, (int) DB::table('products')->where('id', 2)->value('category_id'), 'store product category_id must remap to the relocated category');

        // --- Assert: no data lost, and the obsolete tables are gone ---------------------------------
        $this->assertSame(2, DB::table('products')->count(), 'exactly the legacy + store product survive');
        $this->assertSame(2, DB::table('categories')->count());
        $this->assertFalse(Schema::hasTable('store_products'));
        $this->assertFalse(Schema::hasTable('store_categories'));
    }
}

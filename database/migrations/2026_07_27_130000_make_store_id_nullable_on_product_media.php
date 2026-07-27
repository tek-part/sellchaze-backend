<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The unified catalog is store-less (products.store_id = NULL), so a product's
 * media rows have no store either. Allow store_product_media.store_id to be NULL.
 * Raw ALTER avoids a hard dependency on doctrine/dbal for ->change().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE store_product_media MODIFY store_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE store_product_media MODIFY store_id BIGINT UNSIGNED NOT NULL');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant-managed collections (Shopify "Collections"): automated rule sets and the
 * product sort order. Manual collections keep using the store_collection_product pivot;
 * an automated collection with `rules` resolves its products with a query instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_collections', function (Blueprint $table) {
            $table->json('rules')->nullable()->after('is_automated');
            $table->string('rules_match', 8)->default('all')->after('rules');
            $table->string('sort_order', 32)->default('manual')->after('rules_match');
        });
    }

    public function down(): void
    {
        Schema::table('store_collections', function (Blueprint $table) {
            $table->dropColumn(['rules', 'rules_match', 'sort_order']);
        });
    }
};

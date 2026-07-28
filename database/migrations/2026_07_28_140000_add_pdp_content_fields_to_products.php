<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product content the storefront product pages render but had no backing
 * column for: a "Shipping & returns" note, care instructions, and a highlights
 * (key features) list. Reviews already have their own table (product_reviews),
 * and specifications/dimensions/warranty/material/etc. already exist on products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'shipping_returns')) {
                $table->text('shipping_returns')->nullable()->after('warranty');
            }
            if (! Schema::hasColumn('products', 'care_instructions')) {
                $table->text('care_instructions')->nullable()->after('shipping_returns');
            }
            if (! Schema::hasColumn('products', 'highlights')) {
                $table->json('highlights')->nullable()->after('care_instructions'); // string[] of feature bullets
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['shipping_returns', 'care_instructions', 'highlights'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('product_preferences')->nullable();
            $table->unsignedInteger('display_priority')->default(0);
            $table->boolean('catalog_hidden')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('products')->whereNotNull('product_preferences')->orWhere('display_priority', '!=', 0)->orWhere('catalog_hidden', true)->exists()) {
            throw new RuntimeException('Preserve configured product presentation and priority before rollback.');
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['product_preferences', 'display_priority', 'catalog_hidden']));
    }
};

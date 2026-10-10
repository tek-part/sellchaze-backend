<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_variants', fn (Blueprint $table) => $table->foreignId('image_media_id')->nullable()->constrained('store_product_media')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('store_product_variants', fn (Blueprint $table) => $table->dropConstrainedForeignId('image_media_id'));
    }
};

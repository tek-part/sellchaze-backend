<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_customer_id')->nullable()->change();
            $table->string('external_reference', 160)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->json('media')->nullable();
            $table->unique(['store_id', 'external_reference']);
        });
    }

    public function down(): void
    {
        // Imported guest reviews must be exported or removed explicitly before restoring NOT NULL.
        if (DB::table('product_reviews')->whereNull('store_customer_id')->exists()) {
            throw new RuntimeException('Preserve imported reviews before rolling back this migration.');
        }
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        if ($mysql) {
            Schema::table('product_reviews', fn (Blueprint $table) => $table->dropForeign(['store_customer_id']));
        }
        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->dropUnique(['store_id', 'external_reference']);
            $table->dropColumn(['external_reference', 'source_url', 'media']);
            $table->unsignedBigInteger('store_customer_id')->nullable(false)->change();
        });
        if ($mysql) {
            Schema::table('product_reviews', fn (Blueprint $table) => $table->foreign('store_customer_id')->references('id')->on('store_customers')->cascadeOnDelete());
        }
    }
};

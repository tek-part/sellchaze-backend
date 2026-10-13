<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('manual_actor_id')->nullable();
            $table->unsignedBigInteger('manual_owner_id')->nullable();
            $table->uuid('manual_request_key')->nullable();
            $table->string('manual_request_hash', 64)->nullable();
            $table->decimal('manual_adjustment_total', 12, 2)->nullable();
            $table->boolean('manual_tax_included')->nullable();
            $table->unique(['store_id', 'manual_actor_id', 'manual_request_key'], 'store_orders_manual_request_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('store_orders')->whereNotNull('manual_request_key')->exists()) {
            throw new RuntimeException('Refusing to discard manual order provenance.');
        }
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropUnique('store_orders_manual_request_unique');
            $table->dropColumn(['manual_actor_id', 'manual_owner_id', 'manual_request_key', 'manual_request_hash', 'manual_adjustment_total', 'manual_tax_included']);
        });
    }
};

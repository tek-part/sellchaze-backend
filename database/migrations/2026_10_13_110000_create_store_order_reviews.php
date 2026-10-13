<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_order_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('classification', 20)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('actor_id');
            $table->timestamps();
            $table->index(['store_id', 'classification']);
        });
    }

    public function down(): void
    {
        if (DB::table('store_order_reviews')->exists()) {
            throw new RuntimeException('Order reviews contain merchant decisions. Export them before rollback.');
        }
        Schema::dropIfExists('store_order_reviews');
    }
};

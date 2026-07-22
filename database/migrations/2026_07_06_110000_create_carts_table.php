<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: shopping cart. Store-scoped. A cart belongs either to a logged-in
 * store_customer or to an anonymous guest identified by an opaque `token`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->nullable()->constrained('store_customers')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->string('currency', 3)->default('USD');
            $table->string('status', 20)->default('active'); // active | converted | abandoned
            $table->timestamps();

            $table->index(['store_id', 'store_customer_id']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};

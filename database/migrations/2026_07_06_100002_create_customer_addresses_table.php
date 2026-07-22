<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: customer address book. Store-scoped and owned by a store_customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->constrained('store_customers')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('postal_code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['store_id', 'store_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};

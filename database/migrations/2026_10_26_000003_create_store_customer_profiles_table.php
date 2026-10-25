<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant-owned data about a customer of the order-derived directory (guests included): tags, an
 * internal note and contact overrides, keyed by the directory's customer key (normalized phone, else
 * lower-cased email, else "order:{id}"). Marketing consent itself lives in the newsletter subscriber
 * tables; the profile only records when and by whom the merchant last changed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('customer_key', 255);
            $table->json('tags')->nullable();
            $table->text('note')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamp('marketing_updated_at')->nullable();
            $table->string('marketing_source', 32)->nullable();
            $table->foreignId('marketing_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'customer_key']);
        });
    }

    public function down(): void
    {
        if (DB::table('store_customer_profiles')->exists()) {
            throw new RuntimeException('Export and preserve customer tags and notes before rolling back this migration.');
        }
        Schema::dropIfExists('store_customer_profiles');
    }
};

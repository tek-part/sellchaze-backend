<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_checkout_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('key_hash', 64);
            $table->unsignedInteger('capture_sequence')->default(0);
            $table->string('capture_hash', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->text('shipping_address')->nullable();
            $table->text('notes')->nullable();
            $table->json('shipping_selection')->nullable();
            $table->string('coupon_code', 100)->nullable();
            $table->json('items')->nullable();
            $table->decimal('subtotal', 20, 2)->default(0);
            $table->string('currency', 3);
            $table->string('source', 20)->default('storefront');
            $table->string('source_path', 512)->nullable();
            $table->string('review_status', 20)->default('under_review');
            $table->unsignedInteger('edit_version')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignId('store_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'key_hash']);
            $table->index(['store_id', 'converted_at', 'last_activity_at'], 'checkout_leads_store_activity_index');
            $table->index(['store_id', 'review_status']);
        });
    }

    public function down(): void
    {
        if (DB::table('store_checkout_leads')->exists()) {
            throw new RuntimeException('Preserve checkout leads before rolling back this migration.');
        }
        Schema::dropIfExists('store_checkout_leads');
    }
};

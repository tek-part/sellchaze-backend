<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number', 40)->unique();
            $table->foreignUuid('procurement_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('procurement_quote_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 180);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 40);
            $table->decimal('total', 14, 2);
            $table->string('currency', 8);
            $table->string('status', 24)->default('confirmed');
            $table->timestamp('expected_delivery_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['buyer_organization_id', 'status']);
            $table->index(['supplier_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->decimal('quantity', 14, 3)->default(1);
            $table->string('unit', 40)->default('unit');
            $table->decimal('budget', 14, 2)->nullable();
            $table->string('currency', 8)->default('EGP');
            $table->string('status', 24)->default('draft');
            $table->timestamp('response_deadline')->nullable();
            $table->uuid('awarded_quote_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['buyer_organization_id', 'status']);
            $table->index(['status', 'response_deadline']);
        });

        Schema::create('procurement_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('procurement_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8);
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('submitted');
            $table->timestamps();

            $table->unique(['procurement_request_id', 'supplier_organization_id'], 'procurement_supplier_unique');
            $table->index(['supplier_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_quotes');
        Schema::dropIfExists('procurement_requests');
    }
};

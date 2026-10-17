<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payment_proofs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('reference', 200)->nullable();
            $table->string('status', 30)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'store_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_proofs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 80);
            $table->boolean('enabled')->default(false);
            $table->boolean('test_mode')->default(true);
            $table->text('credentials')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'gateway']);
            $table->index(['store_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_gateways');
    }
};

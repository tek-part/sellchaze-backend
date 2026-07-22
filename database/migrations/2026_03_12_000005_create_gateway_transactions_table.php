<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->string('type', 50); // deposit, withdrawal, fee, order_payment
            $table->decimal('amount', 14, 2);
            $table->string('reference_type')->nullable(); // order, etc
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_transactions');
    }
};

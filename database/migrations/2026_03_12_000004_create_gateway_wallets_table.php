<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_wallets');
    }
};

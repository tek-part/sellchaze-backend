<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('delivery_company', 50); // yahiya, careem, aramex, manual
            $table->string('tracking_number')->nullable();
            $table->string('status', 50)->default('pending'); // pending, in_transit, delivered, failed
            $table->decimal('cod_amount', 12, 2)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_deliveries');
    }
};

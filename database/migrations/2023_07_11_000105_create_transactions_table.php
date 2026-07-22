<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_user_id');
            $table->foreign('supplier_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('customer_user_id');
            $table->foreign('customer_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->longText('orders');
            $table->unsignedBigInteger('amount');
            $table->string('transfer_method');
            $table->string('image')->nullable();
            $table->longText('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_supplier', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('status', 32)->default('accepted');
            $table->unsignedBigInteger('invited_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('supplier_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('invited_by_user_id')->references('id')->on('users')->onDelete('set null');

            $table->unique(['merchant_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_supplier');
    }
};

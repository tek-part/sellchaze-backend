<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_wigpleasure_category_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('wigpleasure_category_id');
            $table->foreignId('supplier_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['merchant_id', 'wigpleasure_category_id'], 'mwcs_merchant_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_wigpleasure_category_suppliers');
    }
};

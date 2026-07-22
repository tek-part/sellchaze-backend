<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 3)->unique();
            $table->decimal('rate_to_usd', 18, 8);
            $table->string('source', 40)->default('manual');
            $table->boolean('is_manual_override')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('owner_type', 20)->index();          // merchant | supplier
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();                  // relative path on public disk
            $table->string('banner')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('currency', 8)->default('USD');
            $table->string('status', 20)->default('draft')->index(); // draft | active | suspended
            $table->timestamps();

            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};

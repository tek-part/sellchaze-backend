<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: per-store retail customers. Distinct from platform `users` (B2B) —
 * a customer identity is scoped to a single store (unique email per store).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'email']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_customers');
    }
};

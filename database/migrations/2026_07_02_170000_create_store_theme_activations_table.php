<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4C: activation history / audit trail for a store's themes. Every activate
 * and rollback records the theme, version, settings snapshot, and actor — enough
 * to reconstruct and roll back to any prior active state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_theme_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('theme_id')->constrained('themes')->cascadeOnDelete();
            $table->foreignId('theme_version_id')->constrained('theme_versions')->cascadeOnDelete();
            $table->json('settings')->nullable();                 // snapshot of active settings
            $table->string('action', 20)->default('activate');    // activate | rollback
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('rollback_from_version_id')->nullable();
            $table->unsignedBigInteger('rollback_to_version_id')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_theme_activations');
    }
};

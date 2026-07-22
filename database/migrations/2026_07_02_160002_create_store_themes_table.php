<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A: per-store theme installs (authoritative). A store may have several
 * installed themes but exactly one active. Each install pins a version and holds
 * its own settings, so switching themes never loses another theme's customization.
 * stores.theme_id / stores.theme_settings mirror the ACTIVE install (fast read).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('theme_id')->constrained('themes')->cascadeOnDelete();
            $table->foreignId('theme_version_id')->constrained('theme_versions')->cascadeOnDelete();
            $table->json('settings')->nullable();             // validated against version.settings_schema
            $table->text('custom_css')->nullable();
            $table->string('status', 20)->default('installed'); // installed|preview|active
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'theme_id']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_themes');
    }
};

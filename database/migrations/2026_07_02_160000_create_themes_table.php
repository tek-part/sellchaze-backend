<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A: theme catalogue (contract plane). Theme *code* lives as versioned
 * bundles elsewhere; this table holds metadata + a pointer to the latest version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();                 // e.g. "default"
            $table->string('name');
            $table->string('author')->nullable();
            $table->string('category')->nullable();
            $table->string('status', 20)->default('published'); // draft|published|deprecated
            $table->boolean('is_marketplace')->default(false);
            $table->string('preview_image')->nullable();
            $table->unsignedBigInteger('latest_version_id')->nullable(); // denormalized pointer (no FK: avoids cycle)
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};

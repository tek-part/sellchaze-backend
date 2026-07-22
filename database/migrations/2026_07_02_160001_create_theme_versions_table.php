<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A: immutable, semver'd theme versions. Each version carries the machine
 * contract (settings_schema, sections_schema, templates) so the platform can build
 * settings UIs / previews WITHOUT executing theme code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained('themes')->cascadeOnDelete();
            $table->string('version', 32);                    // semver
            $table->json('settings_schema');                  // global settings contract
            $table->json('sections_schema');                  // available section types + prop schemas
            $table->json('templates');                        // default section layout per template
            $table->string('bundle_url')->nullable();         // React bundle (Phase 4B+)
            $table->string('min_platform_version', 32)->nullable();
            $table->text('changelog')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['theme_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_versions');
    }
};

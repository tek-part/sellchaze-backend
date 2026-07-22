<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4D (Task 7): theme marketplace assets (preview/gallery/logo). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained('themes')->cascadeOnDelete();
            $table->string('type', 20);            // preview | gallery | logo
            $table->string('path');                // disk-relative path
            $table->string('disk', 32)->default('public');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['theme_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_assets');
    }
};

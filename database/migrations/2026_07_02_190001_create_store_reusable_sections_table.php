<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4E (Task 3): reusable/global sections (one edit affects all assigned pages). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_reusable_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('type', 40);            // section type (must exist in theme sections_schema)
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_reusable_sections');
    }
};

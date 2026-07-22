<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4E (Task 1/2): ordered sections that make up a page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_page_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_page_id')->constrained('store_pages')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('settings')->nullable();
            $table->foreignId('reusable_section_id')->nullable()->constrained('store_reusable_sections')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['store_page_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_page_sections');
    }
};

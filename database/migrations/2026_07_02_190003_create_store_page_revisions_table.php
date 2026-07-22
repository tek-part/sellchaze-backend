<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4E (Task 8): page version history (save / compare / restore). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_page_id')->constrained('store_pages')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->json('snapshot');          // { page: {...}, sections: [...] }
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_page_id', 'revision_number']);
            $table->index(['store_id', 'store_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_page_revisions');
    }
};

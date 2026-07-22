<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4D (Task 5): publishing workflow audit trail. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained('themes')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['theme_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_status_changes');
    }
};

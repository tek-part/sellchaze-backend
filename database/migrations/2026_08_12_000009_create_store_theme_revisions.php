<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_theme_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_theme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('theme_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 24)->default('manual');
            $table->json('settings');
            $table->char('checksum', 64);
            $table->timestamps();

            $table->index(['store_theme_id', 'id']);
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_theme_revisions');
    }
};

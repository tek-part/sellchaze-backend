<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_page_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->char('checksum', 64);
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['store_page_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_page_publications');
    }
};

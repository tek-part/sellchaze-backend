<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_design_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name', 180);
            $table->string('kind', 3);
            $table->string('path');
            $table->unsignedInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['store_id', 'kind', 'checksum_sha256'], 'store_design_assets_content_unique');
        });
        Schema::table('stores', fn (Blueprint $table) => $table->json('design_assets_configuration')->nullable());
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('design_assets_configuration'));
        Schema::dropIfExists('store_design_assets');
    }
};

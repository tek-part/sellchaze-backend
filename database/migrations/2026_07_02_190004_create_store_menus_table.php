<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4E (Task 4/5): navigation menus (header, footer, custom). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('handle', 40);          // header | footer | <custom>
            $table->string('name');
            $table->timestamps();

            $table->unique(['store_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_menus');
    }
};

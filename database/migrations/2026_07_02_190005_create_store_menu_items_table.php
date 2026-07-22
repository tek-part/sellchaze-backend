<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4E (Task 5): nested, typed menu items. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_menu_id')->constrained('store_menus')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();   // nested (self-ref, soft)
            $table->string('label');
            $table->string('type', 20)->default('url');            // internal | category | product | url
            $table->string('target')->nullable();                  // slug | id | url
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['store_menu_id', 'parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_menu_items');
    }
};

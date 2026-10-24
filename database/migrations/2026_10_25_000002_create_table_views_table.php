<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved list views (filters, sort, search, columns) per user and dashboard table, Shopify-style. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Dashboard table key, store-qualified by the client, e.g. "store:12:products" or "admin:merchants".
            $table->string('scope', 120);
            $table->string('name', 60);
            $table->json('state');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'scope', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_views');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_ab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('slug', 100);
            $table->boolean('active')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['store_id', 'slug']);
        });
        Schema::create('store_ab_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained('store_ab_tests')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('targets');
            $table->timestamp('created_at');
            $table->unique(['test_id', 'version']);
        });
        Schema::create('store_ab_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('store_ab_revisions')->cascadeOnDelete();
            $table->char('visitor_hash', 64);
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('product_id');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->unique(['revision_id', 'visitor_hash']);
        });
        Schema::create('store_ab_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained('store_ab_visits')->cascadeOnDelete();
            $table->foreignId('store_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('store_ab_tests')->exists()) {
            throw new RuntimeException('Saved A/B tests and attribution history must be preserved.');
        }
        Schema::dropIfExists('store_ab_conversions');
        Schema::dropIfExists('store_ab_visits');
        Schema::dropIfExists('store_ab_revisions');
        Schema::dropIfExists('store_ab_tests');
    }
};

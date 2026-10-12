<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_service_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('display_name', 100);
            $table->string('headline', 180)->nullable();
            $table->text('bio')->nullable();
            $table->json('skills')->nullable();
            $table->json('portfolio')->nullable();
            $table->string('country', 80)->nullable();
            $table->string('avatar', 160)->nullable();
            $table->json('contacts')->nullable();
            $table->boolean('contacts_public')->default(false);
            $table->boolean('published')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('marketplace_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('marketplace_service_providers')->restrictOnDelete();
            $table->char('creation_hash', 64);
            $table->string('title', 180);
            $table->text('description');
            $table->string('category', 40);
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);
            $table->json('images')->nullable();
            $table->boolean('published')->default(false);
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('ratings_sum')->default(0);
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['published', 'category']);
            $table->index(['published', 'ratings_count', 'rating']);
        });
        Schema::create('marketplace_service_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('service_id')->constrained('marketplace_services')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->timestamps();
            $table->unique(['service_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['marketplace_service_ratings', 'marketplace_services', 'marketplace_service_providers'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Service provider publications and ratings must be preserved before rollback.');
            }
        }
        Schema::dropIfExists('marketplace_service_ratings');
        Schema::dropIfExists('marketplace_services');
        Schema::dropIfExists('marketplace_service_providers');
    }
};

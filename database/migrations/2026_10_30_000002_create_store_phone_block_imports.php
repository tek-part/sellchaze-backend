<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Queued, resumable imports of blocked phone numbers (CSV/XLSX, 100k+ rows). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_phone_block_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_key');
            $table->string('scope', 12)->default('checkout');
            $table->string('status', 12)->default('queued');
            $table->string('file_name', 255);
            $table->string('file_path', 255)->nullable();
            $table->string('format', 8);
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('invalid')->default(0);
            $table->unsignedInteger('skipped_inactive')->default(0);
            $table->text('invalid_samples')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'request_key']);
            $table->index(['status', 'created_at']);
            $table->index(['store_id', 'scope', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_phone_block_imports');
    }
};

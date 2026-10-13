<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable();
            $table->index(['store_order_id', 'superseded_at']);
        });
        Schema::create('store_order_edits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('owner_id');
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->unsignedInteger('revision');
            $table->longText('before_snapshot');
            $table->longText('after_snapshot');
            $table->timestamps();
            $table->unique(['store_id', 'actor_id', 'request_key']);
            $table->unique(['store_order_id', 'revision']);
        });
    }

    public function down(): void
    {
        if (DB::table('store_order_edits')->exists() || DB::table('store_order_items')->whereNotNull('superseded_at')->exists()) {
            throw new RuntimeException('Order edits contain merchant history. Preserve it before rollback.');
        }
        Schema::dropIfExists('store_order_edits');
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->dropIndex(['store_order_id', 'superseded_at']);
            $table->dropColumn('superseded_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('coupon_code', 100)->nullable();
        });
        Schema::create('store_order_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_owner_id');
            $table->uuid('request_key');
            $table->text('filters')->nullable();
            $table->text('order_ids')->nullable();
            $table->unsignedBigInteger('order_boundary');
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->string('artifact_path')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'store_id', 'created_at']);
            $table->index(['status', 'lease_until']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        if (DB::table('store_order_exports')->exists() || DB::table('store_orders')->whereNotNull('coupon_code')->exists()) {
            throw new RuntimeException('Refusing to discard order export history.');
        }
        Schema::dropIfExists('store_order_exports');
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });
    }
};

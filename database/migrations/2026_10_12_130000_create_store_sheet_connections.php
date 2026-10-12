<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_sheet_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->char('creation_hash', 64);
            $table->string('spreadsheet_id', 128);
            $table->string('service_email', 254);
            $table->text('credentials');
            $table->boolean('enabled')->default(false);
            $table->boolean('all_products')->default(false);
            $table->json('product_ids');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('sheet_id');
            $table->string('sheet_title', 100);
            $table->unsignedInteger('next_row')->default(2);
            $table->timestamp('starts_at');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['store_id', 'enabled']);
        });
        Schema::create('store_sheet_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('connection_id')->constrained('store_sheet_connections')->restrictOnDelete();
            $table->foreignId('store_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->text('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->uuid('lease')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'store_order_id']);
            $table->unique(['connection_id', 'row_number']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('store_sheet_connections')->exists() || DB::table('store_sheet_deliveries')->exists()) {
            throw new RuntimeException('Sheet connections and delivery history must be preserved before rollback.');
        }
        Schema::dropIfExists('store_sheet_deliveries');
        Schema::dropIfExists('store_sheet_connections');
    }
};

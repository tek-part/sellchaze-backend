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
            $table->string('status', 64)->default('pending')->change();
        });
        Schema::table('store_order_status_changes', function (Blueprint $table) {
            $table->string('from_status', 64)->nullable()->change();
            $table->string('to_status', 64)->change();
        });
    }

    public function down(): void
    {
        $added = ['waiting_for_pickup', 'returning_from_delivery', 'request_refund', 'refund_in_progress', 'refunded'];
        if (DB::table('store_orders')->whereIn('status', $added)->orWhereRaw('LENGTH(status) > 20')->exists()
            || DB::table('store_order_status_changes')->whereIn('from_status', $added)->orWhereIn('to_status', $added)
                ->orWhereRaw('LENGTH(from_status) > 20 OR LENGTH(to_status) > 20')->exists()) {
            throw new RuntimeException('Refusing to truncate order lifecycle or timeline history.');
        }
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
        Schema::table('store_order_status_changes', function (Blueprint $table) {
            $table->string('from_status', 20)->nullable()->change();
            $table->string('to_status', 20)->change();
        });
    }
};

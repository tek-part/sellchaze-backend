<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->string('segment', 32)->default('to_customer')->after('order_id');
        });

        DB::table('order_deliveries')->whereNull('segment')->update(['segment' => 'to_customer']);

        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->unique(['order_id', 'segment'], 'order_deliveries_order_segment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropUnique('order_deliveries_order_segment_unique');
            $table->dropColumn('segment');
        });
    }
};

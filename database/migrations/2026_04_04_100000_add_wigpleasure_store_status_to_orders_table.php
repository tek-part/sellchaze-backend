<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'wigpleasure_store_status')) {
                $table->string('wigpleasure_store_status', 64)->nullable()->after('wigpleasure_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'wigpleasure_store_status')) {
                $table->dropColumn('wigpleasure_store_status');
            }
        });
    }
};

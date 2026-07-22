<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->foreignId('shipping_company_id')
                ->nullable()
                ->after('order_id')
                ->constrained('shipping_companies')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_company_id');
        });
    }
};

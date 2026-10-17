<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_payment_gateways', function (Blueprint $table) {
            $table->json('presentation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_payment_gateways', fn (Blueprint $table) => $table->dropColumn('presentation'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Existing direct and procurement conversations legitimately have no legacy order.
    }
};

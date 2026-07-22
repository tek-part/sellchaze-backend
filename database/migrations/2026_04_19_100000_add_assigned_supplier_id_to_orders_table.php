<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_supplier_id')->nullable()->after('user_id');
            $table->foreign('assigned_supplier_id')->references('id')->on('users')->nullOnDelete();
            $table->index('assigned_supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['assigned_supplier_id']);
            $table->dropIndex(['assigned_supplier_id']);
            $table->dropColumn('assigned_supplier_id');
        });
    }
};

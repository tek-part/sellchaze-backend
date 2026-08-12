<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->foreignId('target_supplier_organization_id')->nullable()->after('buyer_organization_id')->constrained('organizations')->nullOnDelete();
            $table->index(['target_supplier_organization_id', 'status'], 'procurement_target_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropIndex('procurement_target_status_idx');
            $table->dropConstrainedForeignId('target_supplier_organization_id');
        });
    }
};

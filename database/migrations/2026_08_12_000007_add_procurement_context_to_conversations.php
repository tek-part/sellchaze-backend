<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignUuid('procurement_request_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->foreignUuid('procurement_order_id')->nullable()->after('procurement_request_id')->constrained()->nullOnDelete();
            $table->foreignId('buyer_organization_id')->nullable()->after('procurement_order_id')->constrained('organizations')->nullOnDelete();
            $table->foreignId('supplier_organization_id')->nullable()->after('buyer_organization_id')->constrained('organizations')->nullOnDelete();
            $table->unique(['procurement_request_id', 'supplier_organization_id'], 'conversation_procurement_supplier_unique');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique('conversation_procurement_supplier_unique');
            $table->dropConstrainedForeignId('supplier_organization_id');
            $table->dropConstrainedForeignId('buyer_organization_id');
            $table->dropConstrainedForeignId('procurement_order_id');
            $table->dropConstrainedForeignId('procurement_request_id');
        });
    }
};

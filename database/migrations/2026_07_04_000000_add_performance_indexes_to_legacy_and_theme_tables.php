<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only performance indexes for hot filter/sort columns identified in the
 * production-readiness audit. No columns, data, or existing indexes are modified.
 *
 * Note: orders.assigned_supplier_id is intentionally NOT indexed here — it is
 * already indexed by 2026_04_19_100000_add_assigned_supplier_id_to_orders_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'orders_status_created_at_index');
        });

        Schema::table('order_quotations', function (Blueprint $table) {
            $table->index('status', 'order_quotations_status_index');
            // Column order is (status, supplier_user_id) — deliberately NOT (supplier_user_id, status).
            // supplier_user_id already has its own foreign-key index, which serves the high-selectivity
            // supplier seek for WHERE supplier_user_id = ? AND status = ?. A supplier_user_id-leftmost
            // composite would be redundant with that FK index AND is non-deterministically absorbed by
            // MySQL (version/creation-dependent), which breaks rollback (errno 1553 or duplicate-index).
            // status-leftmost never entangles with the FK, so it is always independently droppable
            // (deterministic reversibility) while still providing a covering index for the equality query.
            $table->index(['status', 'supplier_user_id'], 'order_quotations_supplier_status_index');
        });

        Schema::table('themes', function (Blueprint $table) {
            $table->index(['status', 'is_featured'], 'themes_status_is_featured_index');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->index('theme_id', 'stores_theme_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_created_at_index');
        });

        Schema::table('order_quotations', function (Blueprint $table) {
            // status-leftmost composite is independent of the supplier_user_id FK, so it drops
            // cleanly with no FK-index juggling.
            $table->dropIndex('order_quotations_supplier_status_index');
            $table->dropIndex('order_quotations_status_index');
        });

        Schema::table('themes', function (Blueprint $table) {
            $table->dropIndex('themes_status_is_featured_index');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex('stores_theme_id_index');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the removed supplier/B2B system's tables and leftovers (TAR-027). Runs after the code release
 * that stopped writing to them and after the Supplier→Merchant conversion.
 *
 * Irreversible: take a database backup before running it in production.
 */
return new class extends Migration
{
    /** Children first, so foreign keys never block a drop. */
    private const TABLES = [
        'transactions', 'supplier_payments',
        'ticket_actions', 'ticket_messages', 'order_tickets',
        'order_deliveries', 'shipping_companies',
        'order_quotations', 'order_suppliers', 'product_orders', 'orders',
        'merchant_wigpleasure_category_suppliers', 'merchant_supplier', 'invitations',
        'procurement_audit_entries', 'procurement_quote_revisions', 'procurement_request_suppliers',
        'procurement_orders', 'procurement_quotes', 'procurement_requests',
        'organization_connections', 'organization_sectors',
        'financing_requests', 'investment_opportunities',
        'gateway_transactions', 'gateway_wallets', 'payment_gateways',
    ];

    /** Notification classes that no longer exist. */
    private const NOTIFICATIONS = [
        'App\\Notifications\\InvitationCreated', 'App\\Notifications\\OrderAssignedToSupplierNotification',
        'App\\Notifications\\OrderCreated', 'App\\Notifications\\QuotationApproved', 'App\\Notifications\\QuotationCreated',
        'App\\Notifications\\DeliveryUpdatedNotification', 'App\\Notifications\\TicketCreatedDatabaseNotification',
        'App\\Notifications\\TicketReplyDatabaseNotification', 'App\\Notifications\\UserCreated',
    ];

    public function up(): void
    {
        // Order and procurement threads lose their subject; their messages go with them.
        if (Schema::hasTable('conversations')) {
            $legacy = DB::table('conversations')->whereIn('type', ['order', 'procurement'])->pluck('id');
            foreach ($legacy->chunk(500) as $ids) {
                DB::table('messages')->whereIn('conversation_id', $ids)->delete();
                DB::table('conversation_participants')->whereIn('conversation_id', $ids)->delete();
                DB::table('conversations')->whereIn('id', $ids)->delete();
            }
            $this->dropColumns('conversations', ['order_id', 'procurement_request_id', 'procurement_order_id', 'buyer_organization_id', 'supplier_organization_id']);
        }

        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        $this->dropColumns('categories', ['wigpleasure_category_id']);
        $this->dropColumns('users', ['api_key']);

        DB::table('notifications')->whereIn('type', self::NOTIFICATIONS)->delete();
        if (Schema::hasTable('outbox_messages')) {
            DB::table('outbox_messages')->where('event_type', 'StorefrontOrderBridged')->orWhere('event_type', 'like', 'Procurement%')->delete();
        }
        if (Schema::hasTable('email_templates')) {
            DB::table('email_templates')->whereIn('key', [
                'supplier_order_assigned', 'order_created', 'quotation_created', 'invitation', 'delivery_updated',
                'ticket_created', 'ticket_reply', 'ticket_status_changed', 'quotation_deal_approved',
            ])->delete();
        }
        if (Schema::hasTable('settings')) {
            DB::table('settings')->whereIn('key', ['wigpleasure_pull_base_url', 'wigpleasure_sync_order_statuses'])->delete();
        }
        if (Schema::hasTable('feature_flags')) {
            DB::table('feature_flags')->where('key', 'procurement_v2')->delete();
        }
        if (Schema::hasTable('entitlements')) {
            $rfqs = DB::table('entitlements')->where('key', 'rfqs')->value('id');
            if ($rfqs) {
                DB::table('plan_entitlements')->where('entitlement_id', $rfqs)->delete();
                DB::table('entitlements')->where('id', $rfqs)->delete();
            }
        }
    }

    /** Drop columns together with the foreign keys and indexes that use them (SQLite refuses otherwise). */
    private function dropColumns(string $table, array $columns): void
    {
        $columns = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
        if ($columns === []) {
            return;
        }
        $touches = fn (array $definition) => array_intersect($definition['columns'], $columns) !== [];
        $foreignKeys = collect(Schema::getForeignKeys($table))->filter($touches);
        $indexes = collect(Schema::getIndexes($table))->filter(fn (array $index) => ! $index['primary'] && $touches($index));
        Schema::table($table, function (Blueprint $blueprint) use ($foreignKeys, $indexes, $columns) {
            foreach ($foreignKeys as $fk) {
                $blueprint->dropForeign($fk['name'] ?? $fk['columns']);
            }
            foreach ($indexes as $index) {
                $index['unique'] ? $blueprint->dropUnique($index['name']) : $blueprint->dropIndex($index['name']);
            }
            $blueprint->dropColumn($columns);
        });
    }

    public function down(): void
    {
        // Irreversible: restore from the pre-migration backup.
    }
};

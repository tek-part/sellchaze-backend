<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class B2bTablesDroppedTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2b_tables_and_columns_are_gone_while_store_tables_remain(): void
    {
        foreach (['orders', 'order_quotations', 'order_suppliers', 'order_tickets', 'merchant_supplier', 'invitations', 'procurement_requests', 'organization_connections', 'payment_gateways', 'transactions', 'shipping_companies'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should be dropped");
        }
        foreach (['order_id', 'procurement_request_id', 'buyer_organization_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('conversations', $column), "conversations.{$column} should be dropped");
        }
        $this->assertFalse(Schema::hasColumn('users', 'api_key'));
        $this->assertFalse(Schema::hasColumn('categories', 'wigpleasure_category_id'));

        foreach (['store_orders', 'stores', 'products', 'conversations', 'messages', 'sectors', 'support_tickets', 'merchant_order_push_deliveries'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must stay");
        }
    }
}

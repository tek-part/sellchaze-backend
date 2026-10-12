<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCheckoutLead;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Commerce\CheckoutFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreMissedOrdersFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_checkout_fields_are_not_captured_and_email_only_leads_work(): void
    {
        $owner = User::factory()->create();
        $fields = app(CheckoutFields::class)->defaults();
        foreach ($fields as &$field) {
            $field['enabled'] = $field['key'] === 'email';
        }
        unset($field);
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Fields', 'slug' => 'lead-fields', 'currency' => 'EGP', 'status' => 'active', 'checkout_fields' => $fields]);
        StoreDomain::create(['store_id' => $store->id, 'host' => 'lead-fields.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $product = Product::create(['store_id' => $store->id, 'name' => 'Item', 'slug' => 'item', 'price' => 100, 'is_active' => true]);
        $this->postJson('http://lead-fields.sellchase.com/api/v1/storefront/checkout-leads', ['lead_token' => (string) Str::uuid(), 'sequence' => 1, 'source' => 'storefront',
            'customer_name' => 'Hidden name', 'customer_phone' => '01001234567', 'customer_email' => 'Reader@Example.test', 'notes' => 'Hidden note',
            'shipping_address' => ['line1' => 'Hidden address'], 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertStatus(202);
        $lead = StoreCheckoutLead::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('reader@example.test', $lead->customer_email);
        foreach (['customer_name', 'customer_phone', 'shipping_address', 'notes'] as $key) {
            $this->assertNull($lead->{$key});
        }
    }

    public function test_digital_leads_do_not_capture_delivery_addresses_or_reserve_codes(): void
    {
        $owner = User::factory()->create();
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Digital', 'slug' => 'digital-leads', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $store->id, 'host' => 'digital-leads.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $product = Product::create(['store_id' => $store->id, 'name' => 'Code', 'slug' => 'code', 'price' => 100, 'is_active' => true, 'digital_type' => 'codes']);
        $this->postJson('http://digital-leads.sellchase.com/api/v1/storefront/checkout-leads', ['lead_token' => (string) Str::uuid(), 'sequence' => 1, 'source' => 'funnel',
            'customer_email' => 'reader@example.test', 'shipping_address' => ['line1' => 'Never store for a digital basket'], 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertStatus(202);
        $this->assertNull(StoreCheckoutLead::withoutGlobalScopes()->firstOrFail()->shipping_address);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('product_digital_codes', 0);
    }
}

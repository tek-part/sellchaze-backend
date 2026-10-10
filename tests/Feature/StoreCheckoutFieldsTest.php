<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\StorefrontOrderBridge;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoreCheckoutFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private string $base = 'http://fields.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Fields', 'slug' => 'fields', 'status' => 'active', 'currency' => 'EGP']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'fields.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true]);
    }

    private function fields(array $changes = []): array
    {
        return array_map(fn ($field) => array_replace($field, $changes[$field['key']] ?? []), app(CheckoutFields::class)->defaults());
    }

    private function owner(): static
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->store->owner));
    }

    private function order(array $changes = []): array
    {
        return array_replace_recursive(['customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'shipping_address' => ['line1' => 'Local test address'],
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]]], $changes);
    }

    public function test_owner_can_edit_bilingual_fields_and_public_form_returns_saved_order(): void
    {
        $fields = $this->fields(['phone' => ['position' => 0, 'label' => ['ar' => 'هاتف التواصل', 'en' => 'Contact phone']], 'name' => ['position' => 2]]);
        $this->owner()->putJson('/api/v1/my-store/checkout-fields', ['fields' => $fields])->assertOk()->assertJsonPath('data.0.key', 'phone');
        $this->getJson($this->base.'/checkout/fields?payment_method=cod')->assertOk()->assertJsonPath('data.0.label.ar', 'هاتف التواصل')->assertJsonCount(10, 'data');
        $this->assertSame($fields[1]['label'], $this->store->fresh()->checkout_fields[0]['label']);
    }

    public function test_phone_first_order_has_no_fabricated_email_and_preserves_delivery_extras_in_bridge(): void
    {
        $this->store->update(['checkout_fields' => $this->fields(['phone_alt' => ['enabled' => true], 'national_address' => ['enabled' => true], 'notes' => ['enabled' => true]])]);
        $id = $this->postJson($this->base.'/checkout', $this->order(['shipping_address' => ['phone_alt' => '01111111111', 'national_address' => 'LOCAL1234'], 'notes' => 'Local delivery note']))
            ->assertCreated()->assertJsonPath('data.customer_email', null)->assertJsonPath('data.shipping_address.national_address', 'LOCAL1234')->json('data.id');
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::findOrFail($id);
        $bridge = app(StorefrontOrderBridge::class)->bridge($order, $this->store);
        $this->assertNull($bridge->customer_email);
        $this->assertStringContainsString('01111111111', $bridge->shipping_address);
        $this->assertStringContainsString('LOCAL1234', $bridge->shipping_address);
        $this->assertSame('Local delivery note', $order->notes);
    }

    public function test_required_and_hidden_fields_are_enforced_before_cart_or_order_mutation(): void
    {
        $this->store->update(['checkout_fields' => $this->fields(['national_address' => ['enabled' => true, 'required' => true]])]);
        $this->postJson($this->base.'/checkout', $this->order(['customer_phone' => '']))->assertUnprocessable()->assertJsonValidationErrors(['customer_phone', 'shipping_address.national_address']);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('carts', 0);
        $this->postJson($this->base.'/checkout', $this->order(['customer_email' => 'ignored invalid hidden value', 'shipping_address' => ['national_address' => 'TEST1234', 'city' => ['unchecked']], 'notes' => ['unchecked']]))
            ->assertCreated()->assertJsonPath('data.customer_email', null)->assertJsonPath('data.notes', null)->assertJsonMissingPath('data.shipping_address.city');
    }

    public function test_online_payment_requires_email_and_name_even_if_hidden_and_default_gateway_is_resolved(): void
    {
        $this->store->update(['checkout_fields' => $this->fields(['name' => ['enabled' => false, 'required' => false]])]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'stripe', 'enabled' => true, 'sort_order' => 0, 'credentials' => []]);
        $response = $this->getJson($this->base.'/checkout/fields')->assertOk()->json('data');
        $email = collect($response)->firstWhere('key', 'email');
        $this->assertTrue($email['required']);
        $this->assertTrue($email['enabled']);
        $this->assertTrue($email['payment_required']);
        $this->postJson($this->base.'/checkout', $this->order(['customer_name' => '']))->assertUnprocessable()->assertJsonValidationErrors(['customer_email', 'customer_name']);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_malformed_configuration_and_unreachable_buyer_are_rejected_atomically(): void
    {
        $this->owner();
        $this->putJson('/api/v1/my-store/checkout-fields', ['fields' => $this->fields(['phone' => ['enabled' => false]])])->assertUnprocessable();
        $this->putJson('/api/v1/my-store/checkout-fields', ['fields' => $this->fields(['phone' => ['required' => false]])])->assertUnprocessable();
        $fields = $this->fields();
        $fields[1]['key'] = 'name';
        $this->putJson('/api/v1/my-store/checkout-fields', ['fields' => $fields])->assertUnprocessable();
        $fields = $this->fields();
        $fields[0]['label']['en'] = str_repeat('x', 101);
        $this->putJson('/api/v1/my-store/checkout-fields', ['fields' => $fields])->assertUnprocessable();
        $this->assertNull($this->store->fresh()->checkout_fields);
    }

    public function test_settings_permissions_and_tenant_isolation_apply_to_reads_and_writes(): void
    {
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other))->getJson('/api/v1/stores/'.$this->store->id.'/checkout-fields')->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee))->getJson('/api/v1/my-store/checkout-fields')->assertForbidden();
        $this->putJson('/api/v1/my-store/checkout-fields', ['fields' => $this->fields()])->assertForbidden();
        $employee->givePermissionTo('store.settings.manage');
        $this->travel(31)->seconds(); // JWT user permission cache expires after 30 seconds.
        $this->getJson('/api/v1/my-store/checkout-fields')->assertOk();
        $this->putJson('/api/v1/my-store/checkout-fields', ['fields' => $this->fields()])->assertOk();
        $this->getJson('http://absent.sellchase.com/api/v1/storefront/checkout/fields')->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreMediaAsset;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\StorefrontOrderBridge;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreShippingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $settings;

    private string $base = 'http://shipping.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Shipping', 'slug' => 'shipping', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['ar', 'en'], 'checkout_fields' => app(CheckoutFields::class)->defaults()]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'shipping.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true]);
        $this->settings = ['shipping_enabled' => true, 'shipping_flat_rate' => '25.00', 'shipping_free_over' => null, 'tax_enabled' => true, 'tax_rate' => '10', 'tax_prices_include' => false,
            'regions_enabled' => true, 'auto_select_region' => false,
            'regions' => [
                ['id' => (string) Str::uuid(), 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo'], 'country' => 'EG', 'rate' => '40.00', 'enabled' => true, 'position' => 0],
                ['id' => (string) Str::uuid(), 'name' => ['ar' => 'الإسكندرية', 'en' => 'Alexandria'], 'country' => 'EG', 'rate' => '60.00', 'enabled' => true, 'position' => 1],
            ], 'options' => [
                ['id' => (string) Str::uuid(), 'name' => ['ar' => 'توصيل سريع', 'en' => 'Express'], 'description' => ['ar' => 'خلال يومين', 'en' => 'Within two days'], 'rate' => '85.00', 'enabled' => true, 'is_default' => true, 'priority' => 10, 'icon_asset_id' => null],
            ]];
    }

    private function save(array $settings = []): void
    {
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->store->owner))
            ->putJson('/api/v1/my-store/shipping', $settings ?: $this->settings)->assertOk();
    }

    private function items(): array
    {
        return [['product_id' => $this->product->id, 'quantity' => 1]];
    }

    private function selection(): array
    {
        return ['shipping_region_id' => $this->settings['regions'][0]['id'], 'shipping_option_id' => $this->settings['options'][0]['id']];
    }

    private function order(array $extra = []): array
    {
        return array_merge(['customer_name' => 'Local buyer', 'customer_phone' => '01000000000', 'shipping_address' => ['line1' => 'Local test address', 'city' => 'Forged city', 'country' => 'XX'], 'items' => $this->items()], $this->selection(), $extra);
    }

    public function test_configured_region_is_required_and_shipping_option_replaces_regional_price(): void
    {
        $this->save();
        $form = $this->getJson($this->base.'/checkout/fields')->assertOk()->assertJsonPath('shipping.auto_select_region', false)->assertJsonCount(2, 'shipping.regions')->json('data');
        $this->assertTrue(collect($form)->firstWhere('key', 'city')['shipping_region']);
        $this->assertFalse(collect($form)->firstWhere('key', 'country')['enabled']);
        $payload = ['items' => $this->items()] + $this->selection();
        $this->postJson($this->base.'/checkout/quote', $payload)->assertOk()->assertJsonPath('data.totals.shipping_total', '85.00')->assertJsonPath('data.totals.tax_total', '10.00')->assertJsonPath('data.totals.grand_total', '195.00');
        $this->postJson($this->base.'/checkout', $this->order())->assertCreated()->assertJsonPath('data.shipping_total', '85.00')->assertJsonPath('data.grand_total', '195.00')
            ->assertJsonPath('data.shipping_address.city', 'Cairo')->assertJsonPath('data.shipping_address.country', 'EG')->assertJsonPath('data.shipping_address.delivery_option', 'Express')
            ->assertJsonPath('data.shipping_address.shipping_details.region.id', $this->settings['regions'][0]['id']);
    }

    public function test_region_only_rates_and_free_shipping_use_discounted_subtotal(): void
    {
        $this->settings['options'] = [];
        $this->settings['shipping_free_over'] = 100;
        $this->save();
        $data = ['items' => $this->items(), 'shipping_region_id' => $this->settings['regions'][1]['id']];
        $this->postJson($this->base.'/checkout/quote', $data)->assertOk()->assertJsonPath('data.totals.shipping_total', '0.00');
        Coupon::create(['store_id' => $this->store->id, 'code' => 'LESS20', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
        $this->postJson($this->base.'/checkout/quote', $data + ['coupon_code' => 'LESS20'])->assertOk()->assertJsonPath('data.totals.shipping_total', '60.00')->assertJsonPath('data.totals.tax_total', '8.00')->assertJsonPath('data.totals.grand_total', '148.00');
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_missing_disabled_deleted_and_foreign_selections_fail_before_cart_mutation_even_when_free(): void
    {
        $this->settings['shipping_free_over'] = 0;
        $this->save();
        foreach ([null, (string) Str::uuid()] as $id) {
            $this->postJson($this->base.'/checkout', $this->order(['shipping_region_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('shipping_region_id');
            $this->postJson($this->base.'/checkout', $this->order(['shipping_option_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('shipping_option_id');
        }
        $this->settings['regions'][0]['enabled'] = false;
        $this->save();
        $this->postJson($this->base.'/checkout', $this->order())->assertUnprocessable();
        $this->settings['regions_enabled'] = false;
        $this->settings['options'] = [];
        $this->save();
        $this->postJson($this->base.'/checkout', ['items' => $this->items(), 'customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'shipping_address' => ['line1' => 'Test'], 'shipping_option_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_saved_order_and_bridge_keep_delivery_identity_after_shipping_configuration_changes(): void
    {
        $this->save();
        $id = $this->postJson($this->base.'/checkout', $this->order())->assertCreated()->json('data.id');
        $this->settings['regions'][0]['name']['en'] = 'Renamed';
        $this->settings['options'][0]['rate'] = 999;
        $this->settings['options'][0]['name']['en'] = 'Changed';
        $this->save();
        app(CurrentStore::class)->set($this->store->fresh());
        $order = StoreOrder::findOrFail($id);
        $this->assertSame('Cairo', $order->shipping_address['city']);
        $this->assertSame('85.00', $order->shipping_total);
        $bridge = app(StorefrontOrderBridge::class)->bridge($order, $this->store);
        $this->assertStringContainsString('Express', $bridge->shipping_address);
        $this->assertStringContainsString('Cairo', $bridge->shipping_address);
        $shipping = is_array($bridge->shipping_address_json) ? $bridge->shipping_address_json : json_decode($bridge->shipping_address_json, true);
        $this->assertSame('85.00', $shipping['shipping_details']['base_rate']);
    }

    public function test_invalid_settings_and_foreign_icons_are_rejected_without_overwriting_valid_configuration(): void
    {
        $this->save();
        $bad = $this->settings;
        $bad['regions'][0]['rate'] = -1;
        $this->putJson('/api/v1/my-store/shipping', $bad)->assertUnprocessable();
        $bad = $this->settings;
        $bad['regions'] = [];
        $this->putJson('/api/v1/my-store/shipping', $bad)->assertUnprocessable();
        $bad = $this->settings;
        $bad['options'][0]['enabled'] = false;
        $this->putJson('/api/v1/my-store/shipping', $bad)->assertUnprocessable();
        $foreignStore = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other']);
        $asset = StoreMediaAsset::create(['store_id' => $foreignStore->id, 'disk' => 'public', 'path' => 'test.png', 'original_name' => 'test.png', 'mime' => 'image/png', 'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64)]);
        $bad = $this->settings;
        $bad['options'][0]['icon_asset_id'] = $asset->id;
        $this->putJson('/api/v1/my-store/shipping', $bad)->assertUnprocessable()->assertJsonValidationErrors('options.0.icon_asset_id');
        $this->assertSame('85.00', $this->store->fresh()->shipping_configuration['options'][0]['rate']);
    }

    public function test_unauthorized_employee_cannot_read_or_change_shipping_and_legacy_flat_rate_remains_supported(): void
    {
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee))->getJson('/api/v1/my-store/shipping')->assertForbidden();
        $this->putJson('/api/v1/my-store/shipping', $this->settings)->assertForbidden();
        $this->store->update(['shipping_enabled' => true, 'shipping_flat_rate' => '17.50']);
        $this->postJson($this->base.'/checkout/quote', ['items' => $this->items()])->assertOk()->assertJsonPath('data.totals.shipping_total', '17.50');
        $this->getJson('http://missing.sellchase.com/api/v1/storefront/checkout/fields')->assertNotFound();
    }

    public function test_public_options_are_active_sorted_and_normalized_with_store_owned_icons(): void
    {
        $asset = StoreMediaAsset::create(['store_id' => $this->store->id, 'disk' => 'public', 'path' => 'shipping/local-icon.png', 'original_name' => 'local-icon.png', 'mime' => 'image/png', 'size_bytes' => 1, 'checksum_sha256' => str_repeat('b', 64)]);
        $this->settings['auto_select_region'] = '0';
        $this->settings['regions'][0]['enabled'] = '1';
        $this->settings['options'][0]['is_default'] = '1';
        $this->settings['options'][0]['icon_asset_id'] = $asset->id;
        $second = $this->settings['options'][0];
        $second['id'] = (string) Str::uuid();
        $second['is_default'] = '0';
        $second['priority'] = '20';
        $this->settings['options'][] = $second;
        $hidden = $second;
        $hidden['id'] = (string) Str::uuid();
        $hidden['enabled'] = '0';
        $this->settings['options'][] = $hidden;
        $this->save();
        $response = $this->getJson($this->base.'/checkout/fields')->assertOk()
            ->assertJsonPath('shipping.auto_select_region', false)
            ->assertJsonPath('shipping.regions.0.enabled', true)
            ->assertJsonCount(2, 'shipping.options')
            ->assertJsonPath('shipping.options.0.id', $second['id'])
            ->assertJsonPath('shipping.options.0.priority', 20)
            ->assertJsonPath('shipping.options.0.is_default', false)
            ->assertJsonPath('shipping.options.1.is_default', true);
        $this->assertStringContainsString('/storage/shipping/local-icon.png', $response->json('shipping.options.0.icon_url'));
        $asset->delete();
        $this->getJson($this->base.'/checkout/fields')->assertOk()->assertJsonPath('shipping.options.0.icon_url', null);
    }
}

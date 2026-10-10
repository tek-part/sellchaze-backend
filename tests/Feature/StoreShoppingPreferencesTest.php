<?php

namespace Tests\Feature;

use App\Http\Resources\Storefront\StorefrontProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Storefront\StorefrontService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoreShoppingPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $settings = '/api/v1/my-store/shopping-preferences';

    private string $base = 'http://shopping.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Shopping', 'slug' => 'shopping', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'shopping.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true]);
    }

    public function test_settings_are_versioned_validated_and_tenant_permission_scoped(): void
    {
        $this->getJson($this->settings, $this->auth)->assertOk()->assertJsonPath('data', ['auto_select_variants' => true, 'auto_select_shipping_region' => true, 'version' => 1]);
        $this->putJson($this->settings, ['auto_select_variants' => false, 'version' => 1], $this->auth)->assertOk()->assertJsonPath('data', ['auto_select_variants' => false, 'auto_select_shipping_region' => true, 'version' => 2]);
        $this->putJson($this->settings, ['auto_select_variants' => true, 'version' => 1], $this->auth)->assertConflict();
        $this->getJson($this->settings, $this->auth)->assertOk()->assertJsonPath('data.auto_select_variants', false);
        $this->putJson($this->settings, ['auto_select_variants' => 'invalid', 'version' => 2], $this->auth)->assertUnprocessable();
        $this->putJson($this->settings, ['auto_select_variants' => true], $this->auth)->assertUnprocessable();
        $this->putJson($this->settings, ['auto_select_variants' => true, 'auto_select_shipping_region' => 'invalid', 'version' => 2], $this->auth)->assertUnprocessable();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/shopping-preferences', ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)])->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->settings, $headers)->assertForbidden();
        $this->putJson($this->settings, ['auto_select_variants' => true, 'auto_select_shipping_region' => false, 'version' => 2], $headers)->assertForbidden();
        $employee->givePermissionTo('store.settings.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->settings, $headers)->assertOk();
        $this->putJson($this->settings, ['auto_select_variants' => true, 'version' => 2], $headers)->assertOk()->assertJsonPath('data.version', 3);
    }

    public function test_delivery_region_setting_reuses_existing_shipping_configuration_and_preserves_every_other_value(): void
    {
        $configuration = ['regions_enabled' => true, 'auto_select_region' => false,
            'regions' => [['id' => 'existing-region', 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo'], 'country' => 'EG', 'rate' => '40.00', 'enabled' => true, 'position' => 0]],
            'options' => [['id' => 'existing-option', 'rate' => '85.00', 'enabled' => true, 'is_default' => true]], 'future_setting' => ['preserve' => true]];
        $this->store->update(['shipping_configuration' => $configuration, 'shipping_enabled' => true, 'shipping_flat_rate' => '25.00', 'shipping_free_over' => '500.00', 'tax_enabled' => true, 'tax_rate' => '10.00', 'tax_prices_include' => true]);
        $before = $this->store->fresh()->getAttributes();
        $this->getJson($this->settings, $this->auth)->assertOk()->assertJsonPath('data.auto_select_shipping_region', false);
        // Older variant-only clients must retain the merchant's delivery policy.
        $this->putJson($this->settings, ['auto_select_variants' => false, 'version' => 1], $this->auth)->assertOk()->assertJsonPath('data.auto_select_shipping_region', false);
        $this->assertSame($configuration, $this->store->fresh()->shipping_configuration);
        $this->putJson($this->settings, ['auto_select_variants' => false, 'auto_select_shipping_region' => true, 'version' => 2], $this->auth)->assertOk()->assertJsonPath('data.version', 3);
        $this->assertSame(array_replace($configuration, ['auto_select_region' => true]), $this->store->fresh()->shipping_configuration);
        foreach (['shipping_enabled', 'shipping_flat_rate', 'shipping_free_over', 'tax_enabled', 'tax_rate', 'tax_prices_include'] as $key) {
            $this->assertSame($before[$key], $this->store->fresh()->getAttributes()[$key]);
        }
        $this->assertSame(['auto_select_variants' => false, 'version' => 3], $this->store->fresh()->shopping_preferences);
        $this->getJson($this->base.'/checkout/fields')->assertOk()->assertJsonPath('shipping.auto_select_region', true);
        $this->putJson($this->settings, ['auto_select_variants' => true, 'auto_select_shipping_region' => false, 'version' => 2], $this->auth)->assertConflict();
        $this->getJson($this->base.'/checkout/fields')->assertOk()->assertJsonPath('shipping.auto_select_region', true);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_all_public_product_payloads_refresh_after_a_preference_change(): void
    {
        $this->getJson($this->base.'/')->assertOk()->assertJsonPath('homepage.featured_products.0.auto_select_variant', true);
        $this->getJson($this->base.'/')->assertOk()->assertJsonPath('store.auto_select_variants', true);
        $this->putJson($this->settings, ['auto_select_variants' => false, 'version' => 1], $this->auth)->assertOk();
        $this->getJson($this->base.'/')->assertOk()->assertJsonPath('homepage.featured_products.0.auto_select_variant', false);
        $this->getJson($this->base.'/')->assertOk()->assertJsonPath('store.auto_select_variants', false);
        $this->getJson($this->base.'/products/bag')->assertOk()->assertJsonPath('data.auto_select_variant', false);
        $this->getJson($this->base.'/products')->assertOk()->assertJsonPath('data.0.auto_select_variant', false);
        $this->postJson($this->base.'/cart/catalog', ['product_ids' => [$this->product->id]])->assertOk()->assertJsonPath('data.0.auto_select_variant', false);
        $this->putJson($this->settings, ['auto_select_variants' => true, 'version' => 2], $this->auth)->assertOk();
        $this->getJson($this->base.'/')->assertOk()->assertJsonPath('homepage.featured_products.0.auto_select_variant', true);
    }

    public function test_request_store_preference_is_used_for_shared_catalog_and_does_not_leak_between_stores(): void
    {
        $other = Store::create(['owner_user_id' => $this->store->owner_user_id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-shopping', 'currency' => 'EGP', 'status' => 'active', 'shopping_preferences' => ['auto_select_variants' => false, 'version' => 1]]);
        app(CurrentStore::class)->set($other);
        $this->assertFalse(app(StorefrontService::class)->productArray($this->product)['auto_select_variant']);
        $this->assertFalse((new StorefrontProductResource($this->product))->toArray(request())['auto_select_variant']);
        app(CurrentStore::class)->set($this->store);
        $this->assertTrue(app(StorefrontService::class)->productArray($this->product)['auto_select_variant']);
    }

    public function test_server_always_requires_explicit_variant_even_with_automatic_selection_enabled(): void
    {
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Blue', 'is_active' => true, 'price_override' => 125]);
        foreach ([true, false] as $automatic) {
            $this->store->update(['shopping_preferences' => ['auto_select_variants' => $automatic, 'version' => 1]]);
            $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'quantity' => 1])->assertUnprocessable();
            $this->postJson($this->base.'/checkout/quote', ['items' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertUnprocessable();
            $this->postJson($this->base.'/checkout/quote', ['items' => [['product_id' => $this->product->id, 'variant_id' => $variant->id, 'quantity' => 1]]])->assertOk()->assertJsonPath('data.totals.subtotal', '125.00');
        }
        $this->assertDatabaseCount('store_orders', 0);
    }
}

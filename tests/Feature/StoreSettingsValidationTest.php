<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\CurrencyRatesSeeder;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * UpdateStoreRequest hardening for the owner settings form: status is not
 * writable by owners (publish/unpublish only), currencies are normalised to
 * uppercase and must exist in currency_rates, and the store-scoped currency
 * list endpoint exposes the available codes.
 */
class StoreSettingsValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $this->seed(StorePermissionsSeeder::class);
        $this->seed(CurrencyRatesSeeder::class);
    }

    private function owner(string $role = 'Merchant'): User
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole($role);

        return $user;
    }

    private function asUser(User $user): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    private function provisionedStore(User $user): Store
    {
        $this->asUser($user)->getJson('/api/v1/my-store')->assertOk();

        return $user->fresh()->store;
    }

    public function test_owner_status_input_is_ignored(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $this->assertSame('draft', $store->status);

        $this->asUser($owner)->putJson('/api/v1/my-store', ['status' => 'active', 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.name', 'Renamed');
        $this->assertSame('draft', $store->fresh()->status);
    }

    public function test_admin_can_still_set_status_directly(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $admin = $this->owner('Admin');

        $this->asUser($admin)->putJson("/api/v1/stores/{$store->id}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    public function test_unknown_currency_is_rejected(): void
    {
        $owner = $this->owner();
        $this->provisionedStore($owner);

        $this->asUser($owner)->putJson('/api/v1/my-store', ['currency' => 'XXX'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);

        $this->asUser($owner)->putJson('/api/v1/my-store', ['supported_currencies' => ['USD', 'ZZZ']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supported_currencies.1']);
    }

    public function test_lowercase_currencies_are_uppercased_before_validation(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);

        $this->asUser($owner)->putJson('/api/v1/my-store', [
            'currency' => 'aed',
            'supported_currencies' => ['aed', 'usd'],
        ])->assertOk()
            ->assertJsonPath('data.currency', 'AED');

        $fresh = $store->fresh();
        $this->assertSame('AED', $fresh->currency);
        $this->assertSame(['AED', 'USD'], array_values((array) $fresh->supported_currencies));
    }

    public function test_store_scoped_currency_codes_endpoint(): void
    {
        $owner = $this->owner('Merchant');
        $this->provisionedStore($owner);

        $response = $this->asUser($owner)->getJson('/api/v1/my-store/currencies')->assertOk();
        $codes = $response->json('data');

        $this->assertContains('USD', $codes);
        $this->assertContains('AED', $codes);
        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes, 'codes are sorted');
        $this->assertSame($codes, array_map('strtoupper', $codes), 'codes are uppercase');
    }

    public function test_localization_save_rejects_a_base_currency_conflicting_with_pinned_history_as_a_field_error(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $policy = ['version' => 'fixture-v1', 'reporting_currency' => 'KWD', 'rates' => ['USD' => '0.308150', 'KWD' => '1'],
            'source_url' => 'https://example.test/rates', 'effective_date' => '2026-10-27'];
        $store->update(['currency' => 'KWD', 'default_locale' => 'ar', 'supported_locales' => ['ar', 'en'], 'timezone' => 'UTC',
            'theme_settings' => ['reporting_money_policy' => $policy]]);
        Storage::fake('public');
        Storage::disk('public')->put('stores/original.png', 'original-image');
        $store->update(['logo' => 'stores/original.png']);
        $category = Category::create(['store_id' => $store->id, 'user_id' => $owner->id, 'name' => 'Fixture', 'slug' => 'fixture-category']);
        $product = Product::create(['store_id' => $store->id, 'user_id' => $owner->id, 'category_id' => $category->id, 'name' => 'Fixture', 'slug' => 'fixture-product', 'price' => 10, 'is_active' => true]);
        $order = StoreOrder::create(['store_id' => $store->id, 'order_number' => 'FX-1', 'currency' => 'USD', 'customer_name' => 'Fixture', 'status' => 'delivered', 'subtotal' => 100, 'grand_total' => 100, 'placed_at' => now()]);
        $related = [$category->fresh()->getRawOriginal(), $product->fresh()->getRawOriginal(), $order->fresh()->getRawOriginal()];
        $before = $store->fresh()->getRawOriginal();

        $this->asUser($owner)->post('/api/v1/my-store', [
            '_method' => 'PUT', 'default_locale' => 'en', 'supported_locales' => ['ar', 'en'],
            'currency' => 'USD', 'supported_currencies' => ['KWD', 'USD'],
            'auto_currency_by_country' => '1', 'timezone' => 'Asia/Kuwait',
            'name' => 'Changed name', 'slug' => 'changed-slug', 'logo' => UploadedFile::fake()->image('replacement.png'),
        ], ['Accept' => 'application/json', 'Accept-Language' => 'ar'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency'])
            ->assertJsonPath('errors.currency.0', 'عملة المتجر الأساسية مرتبطة بتحويلات التقارير التاريخية. يمكنك إضافة عملة للعرض دون تغيير العملة الأساسية. يتطلب تغيير العملة الأساسية تحديث الأسعار وتحويلات التقارير أولًا.');

        $this->assertSame($before, $store->fresh()->getRawOriginal());
        $this->assertSame($related, [$category->fresh()->getRawOriginal(), $product->fresh()->getRawOriginal(), $order->fresh()->getRawOriginal()]);
        $this->assertSame(['stores/original.png'], Storage::disk('public')->allFiles());
        $this->assertSame('original-image', Storage::disk('public')->get('stores/original.png'));
    }

    public function test_enabled_deema_rejects_base_currency_change_but_allows_display_currency_and_disabled_deema_allows_change(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $store->update(['currency' => 'KWD']);
        $gateway = StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'deema', 'enabled' => true, 'test_mode' => true]);
        $before = $store->fresh()->getRawOriginal();

        $this->asUser($owner)->putJson('/api/v1/my-store', ['currency' => 'USD', 'name' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['currency'])
            ->assertJsonPath('errors.currency.0', 'Deema requires KWD as the store base currency. Disable Deema before changing the base currency, or add a display currency instead.');
        $this->assertSame($before, $store->fresh()->getRawOriginal());
        $this->assertTrue($gateway->fresh()->enabled);

        $this->asUser($owner)->putJson('/api/v1/my-store', ['currency' => 'KWD', 'supported_currencies' => ['KWD', 'USD']])
            ->assertOk()->assertJsonPath('data.currency', 'KWD');
        $this->assertSame(['KWD', 'USD'], $store->fresh()->supported_currencies);

        $gateway->update(['enabled' => false]);
        $this->asUser($owner)->putJson('/api/v1/my-store', ['currency' => 'USD'])
            ->assertOk()->assertJsonPath('data.currency', 'USD');
    }

    public function test_localization_can_save_display_currencies_and_locale_without_rebasing_pinned_history(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $policy = ['version' => 'fixture-v1', 'reporting_currency' => 'KWD', 'rates' => ['USD' => '0.308150', 'KWD' => '1'],
            'source_url' => 'https://example.test/rates', 'effective_date' => '2026-10-27'];
        $store->update(['currency' => 'KWD', 'theme_settings' => ['reporting_money_policy' => $policy]]);

        $this->asUser($owner)->post('/api/v1/my-store', [
            '_method' => 'PUT', 'default_locale' => 'ar', 'supported_locales' => ['ar', 'en'],
            'currency' => 'KWD', 'supported_currencies' => ['KWD', 'USD'],
            'auto_currency_by_country' => '1', 'timezone' => 'Asia/Kuwait',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.currency', 'KWD')
            ->assertJsonPath('data.default_locale', 'ar')
            ->assertJsonPath('data.auto_currency_by_country', true)
            ->assertJsonPath('data.timezone', 'Asia/Kuwait');

        $fresh = $store->fresh();
        $this->assertSame(['KWD', 'USD'], $fresh->supported_currencies);
        $this->assertSame(['ar', 'en'], $fresh->supported_locales);
        $this->assertSame($policy, $fresh->theme_settings['reporting_money_policy']);
    }

    public function test_legacy_deema_blocks_currency_change_until_explicitly_disabled_by_a_native_setting(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $store->update(['currency' => 'KWD', 'theme_settings' => ['payment_gateways' => [['slug' => 'deema', 'enabled' => true]]]]);
        $before = $store->fresh()->getRawOriginal();
        $this->asUser($owner)->putJson('/api/v1/my-store', ['currency' => 'USD'])
            ->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        $this->assertSame($before, $store->fresh()->getRawOriginal());

        StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'deema', 'enabled' => false, 'test_mode' => true]);
        $this->asUser($owner)->putJson('/api/v1/my-store', ['currency' => 'USD'])
            ->assertOk()->assertJsonPath('data.currency', 'USD');
    }

    public function test_localization_can_save_after_a_coordinated_usd_reporting_migration(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $policy = ['version' => 'fixture-usd-v1', 'reporting_currency' => 'USD', 'rates' => ['USD' => '1', 'KWD' => '3.245172805452'],
            'source_url' => 'https://example.test/rates', 'effective_date' => '2026-10-27'];
        $store->update(['currency' => 'USD', 'theme_settings' => ['reporting_money_policy' => $policy]]);
        StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'deema', 'enabled' => false, 'test_mode' => true]);

        $this->asUser($owner)->post('/api/v1/my-store', ['_method' => 'PUT', 'currency' => 'USD', 'default_locale' => 'ar',
            'supported_currencies' => ['USD', 'KWD'], 'timezone' => 'Asia/Kuwait'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.timezone', 'Asia/Kuwait');
        $this->assertSame($policy, $store->fresh()->theme_settings['reporting_money_policy']);
    }
}

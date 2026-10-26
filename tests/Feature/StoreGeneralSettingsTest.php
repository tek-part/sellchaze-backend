<?php

namespace Tests\Feature;

use App\Models\CurrencyRate;
use App\Models\Store;
use App\Models\User;
use App\Services\Commerce\MerchantGeneralSettings;
use App\Services\Commerce\ShoppingPreferences;
use App\Services\Commerce\StoreShipping;
use App\Services\JwtTokenService;
use Database\Seeders\CurrencyRatesSeeder;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreGeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private string $endpoint = '/api/v1/my-store/general-settings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class, StorePermissionsSeeder::class, CurrencyRatesSeeder::class]);
        CurrencyRate::create(['currency_code' => 'EGP', 'rate_to_usd' => 1, 'source' => 'test', 'is_manual_override' => true]);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($owner));
        $this->getJson('/api/v1/my-store')->assertOk();
        $this->store = $owner->fresh()->store;
    }

    private function payload(array $changes = []): array
    {
        $data = app(MerchantGeneralSettings::class)->data($this->store->fresh());
        unset($data['logo_url'], $data['banner_url'], $data['favicon_url'], $data['bot_credentials_configured']);

        return array_replace($data, $changes);
    }

    public function test_read_is_private_and_does_not_modify_settings_or_return_bot_secrets(): void
    {
        $before = $this->store->fresh()->getRawOriginal();
        $response = $this->getJson($this->endpoint)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $data = $response->json('data');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['revision']);
        $this->assertArrayNotHasKey('bot_protection_credentials', $data);
        $this->assertArrayNotHasKey('site_key', $data);
        $this->assertArrayNotHasKey('secret_key', $data);
        $this->assertSame($before, $this->store->fresh()->getRawOriginal());
        $this->assertContains('EGP', $response->json('choices.currencies'));
        $this->assertContains('EG', $response->json('choices.countries'));
    }

    public function test_compound_save_preserves_other_checkout_settings_and_changes_real_shipping_quote(): void
    {
        $this->store->update(['shipping_enabled' => false, 'shipping_flat_rate' => '70.00', 'shipping_free_over' => '500.00',
            'shopping_preferences' => ['version' => 4, 'auto_select_variants' => true, 'future_setting' => 'keep'],
            'order_limits' => ['version' => 2, 'phone_country' => 'EG', 'max_product_quantity' => 0, 'max_orders_per_phone_24h' => 0, 'minimum_order_amount' => '45.00'],
            'theme_settings' => ['kept' => true]]);
        $this->putJson($this->endpoint, $this->payload(['site_title' => 'متجر الاختبار', 'header_text' => 'شحن سريع',
            'primary_color' => '#123456', 'font_family' => 'Almarai', 'default_locale' => 'ar', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'shipping_flat_rate' => '25.50', 'disable_auto_select_shipping' => true, 'disable_auto_select_variants' => true,
            'max_product_quantity' => 3, 'max_orders_per_phone_24h' => 2]))->assertOk()
            ->assertJsonPath('data.shipping_flat_rate', '25.50')->assertJsonPath('data.max_product_quantity', 3);
        $saved = $this->store->fresh();
        $this->assertSame('custom', $saved->header_mode);
        $this->assertSame('45.00', $saved->order_limits['minimum_order_amount']);
        $this->assertSame(3, $saved->order_limits['version']);
        $this->assertSame(5, $saved->shopping_preferences['version']);
        $this->assertSame('keep', $saved->shopping_preferences['future_setting']);
        $this->assertSame(['kept' => true], $saved->theme_settings);
        $this->assertSame('500.00', $saved->shipping_free_over);
        $this->assertFalse(app(ShoppingPreferences::class)->configured($saved)['auto_select_shipping_region']);
        $this->assertSame('25.50', app(StoreShipping::class)->quote($saved, '100.00')['amount']);
        $this->assertSame('0.00', app(StoreShipping::class)->quote($saved, '500.00')['amount']);
    }

    public function test_identity_only_save_does_not_enable_shipping_or_discard_regions_and_options(): void
    {
        $config = ['regions_enabled' => false, 'auto_select_region' => true, 'regions' => [['retained' => 'region']], 'options' => [['retained' => 'option']]];
        $this->store->update(['shipping_enabled' => false, 'shipping_flat_rate' => '70.00', 'shipping_configuration' => $config, 'header_mode' => 'theme']);
        $this->putJson($this->endpoint, $this->payload(['site_title' => 'Changed title', 'disable_auto_select_shipping' => true]))->assertOk();
        $saved = $this->store->fresh();
        $this->assertFalse($saved->shipping_enabled);
        $this->assertSame('70.00', $saved->shipping_flat_rate);
        $this->assertSame('theme', $saved->header_mode);
        $this->assertSame($config['regions'], $saved->shipping_configuration['regions']);
        $this->assertSame($config['options'], $saved->shipping_configuration['options']);
    }

    public function test_stale_save_after_another_settings_editor_is_rejected_before_upload(): void
    {
        $stale = $this->payload(['site_title' => 'Stale overwrite', 'logo' => UploadedFile::fake()->image('new.png')]);
        $this->store->update(['shopping_preferences' => ['auto_select_variants' => false, 'version' => 2]]);
        $this->postJson($this->endpoint, ['_method' => 'PUT', ...$stale])->assertConflict();
        $this->assertNull($this->store->fresh()->site_title);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertFalse($this->store->fresh()->shopping_preferences['auto_select_variants']);
    }

    public function test_invalid_values_or_missing_bot_credentials_cannot_partially_save(): void
    {
        $before = $this->store->fresh()->getRawOriginal();
        $this->putJson($this->endpoint, $this->payload(['site_title' => 'Must not persist', 'shipping_flat_rate' => '1.001', 'phone_country' => 'INVALID']))
            ->assertUnprocessable()->assertJsonValidationErrors(['shipping_flat_rate', 'phone_country']);
        $this->putJson($this->endpoint, $this->payload(['site_title' => 'Still must not persist', 'disable_bot_protection' => false]))->assertUnprocessable();
        $this->assertSame($before, $this->store->fresh()->getRawOriginal());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_database_update_keeps_old_image_and_cleans_staged_replacement(): void
    {
        $path = 'stores/'.$this->store->id.'/identity/old.png';
        Storage::disk('public')->put($path, 'old-image');
        $this->store->update(['logo' => $path]);
        $before = $this->store->fresh()->getRawOriginal();
        DB::unprepared("CREATE TRIGGER fail_general_save BEFORE UPDATE ON stores WHEN NEW.site_title = 'FAIL_AFTER_UPLOAD' BEGIN SELECT RAISE(ABORT, 'test database failure'); END");
        try {
            $this->postJson($this->endpoint, ['_method' => 'PUT', ...$this->payload(['site_title' => 'FAIL_AFTER_UPLOAD', 'logo' => UploadedFile::fake()->image('new.png')])])->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER fail_general_save');
        }
        $this->assertSame($before, $this->store->fresh()->getRawOriginal());
        $this->assertSame([$path], Storage::disk('public')->allFiles());
        $this->assertSame('old-image', Storage::disk('public')->get($path));
    }

    public function test_replacement_cleans_only_unreferenced_images_after_commit(): void
    {
        $path = 'stores/'.$this->store->id.'/identity/old.png';
        Storage::disk('public')->put($path, 'old-image');
        $this->store->update(['logo' => $path, 'banner' => $path]);
        $this->postJson($this->endpoint, ['_method' => 'PUT', ...$this->payload(['logo' => UploadedFile::fake()->image('new.png')])])->assertOk();
        $replacement = $this->store->fresh()->logo;
        Storage::disk('public')->assertExists($replacement);
        Storage::disk('public')->assertExists($path);
        $this->postJson($this->endpoint, ['_method' => 'PUT', ...$this->payload(['remove_logo' => true])])->assertOk();
        Storage::disk('public')->assertMissing($replacement);
        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $this->store->fresh()->banner);
    }

    public function test_an_admin_reads_and_updates_general_settings_in_the_store_scope(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin));
        $endpoint = '/api/v1/stores/'.$this->store->id.'/general-settings';
        $this->getJson($endpoint)->assertOk()->assertJsonStructure(['data' => ['revision', 'site_title'], 'choices' => ['currencies']]);
        $this->putJson($endpoint, $this->payload(['site_title' => 'Admin title']))->assertOk()->assertJsonPath('data.site_title', 'Admin title');
        $this->assertSame('Admin title', $this->store->fresh()->site_title);
    }

    public function test_another_merchant_cannot_read_or_update_this_store(): void
    {
        $payload = $this->payload(['site_title' => 'Foreign overwrite']);
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other));
        $this->getJson('/api/v1/stores/'.$this->store->id.'/general-settings')->assertNotFound();
        $this->putJson('/api/v1/stores/'.$this->store->id.'/general-settings', $payload)->assertNotFound();
        $this->assertNull($this->store->fresh()->site_title);
    }

    public function test_legacy_currency_without_conversion_rate_can_be_preserved_but_not_selected_for_another_store(): void
    {
        $this->store->update(['currency' => 'EGP']);
        CurrencyRate::where('currency_code', 'EGP')->delete();
        $this->getJson($this->endpoint)->assertOk()->assertJsonPath('choices.currencies', fn ($codes) => in_array('EGP', $codes, true));
        $this->putJson($this->endpoint, $this->payload(['site_title' => 'Legacy currency kept']))->assertOk();
        $this->assertSame('EGP', $this->store->fresh()->currency);
        $this->putJson($this->endpoint, $this->payload(['currency' => 'XYZ']))->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertFalse(CurrencyRate::where('currency_code', 'EGP')->exists());
    }

    public function test_header_mode_can_be_chosen_including_back_to_the_theme_header(): void
    {
        $this->getJson($this->endpoint)->assertOk()->assertJsonPath('data.header_mode', 'theme');

        $this->putJson($this->endpoint, $this->payload(['header_mode' => 'custom', 'header_text' => 'Free delivery today']))->assertOk()
            ->assertJsonPath('data.header_mode', 'custom')->assertJsonPath('data.header_text', 'Free delivery today');

        // Back to the theme's own header: the text is kept for later but no longer shown.
        $this->putJson($this->endpoint, $this->payload(['header_mode' => 'theme']))->assertOk()->assertJsonPath('data.header_mode', 'theme');
        $saved = $this->store->fresh();
        $this->assertSame('theme', $saved->header_mode);
        $this->assertSame('Free delivery today', $saved->header_text);
        $this->assertSame('theme', $saved->identity()['header_mode']);

        $this->putJson($this->endpoint, $this->payload(['header_mode' => 'hidden']))->assertOk();
        $this->assertSame('hidden', $this->store->fresh()->header_mode);

        $this->putJson($this->endpoint, $this->payload(['header_mode' => 'banner']))->assertStatus(422)->assertJsonValidationErrors('header_mode');
        $this->assertSame('hidden', $this->store->fresh()->header_mode);
    }

    public function test_banner_can_be_uploaded_replaced_and_removed(): void
    {
        $this->postJson($this->endpoint, ['_method' => 'PUT', ...$this->payload(['banner' => UploadedFile::fake()->image('hero.jpg', 1600, 600)])])->assertOk();
        $first = $this->store->fresh()->banner;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);
        $this->getJson($this->endpoint)->assertOk()->assertJsonPath('data.banner_url', Storage::disk('public')->url($first));

        $this->postJson($this->endpoint, ['_method' => 'PUT', ...$this->payload(['banner' => UploadedFile::fake()->image('hero2.webp', 1600, 600)])])->assertOk();
        $second = $this->store->fresh()->banner;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $this->postJson($this->endpoint, ['_method' => 'PUT', ...$this->payload(['banner' => UploadedFile::fake()->image('x.png'), 'remove_banner' => true])])->assertStatus(422);
        $this->assertSame($second, $this->store->fresh()->banner);

        $this->postJson($this->endpoint, ['_method' => 'PUT', ...$this->payload(['remove_banner' => true])])->assertOk()->assertJsonPath('data.banner_url', null);
        $this->assertNull($this->store->fresh()->banner);
        Storage::disk('public')->assertMissing($second);
    }
}

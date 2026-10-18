<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreMediaAsset;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_adjustments_validate_boundaries_and_survive_partial_presentation_saves(): void
    {
        [$store, $headers] = $this->owner();
        $rule = ['enabled' => true, 'type' => 'percentage', 'direction' => 'subtract', 'value' => '12.50'];
        $body = ['gateways' => [['slug' => 'cod', 'enabled' => true, 'presentation' => ['price_adjustment' => $rule]]]];
        $this->putJson('/api/v1/my-store/payments', $body, $headers)->assertOk();
        $this->putJson('/api/v1/my-store/payments', ['gateways' => [['slug' => 'cod', 'enabled' => true, 'presentation' => ['name' => 'Pay on arrival']]]], $headers)->assertOk();
        $row = StorePaymentGateway::where('store_id', $store->id)->where('gateway', 'cod')->firstOrFail();
        $this->assertSame($rule, $row->presentation['price_adjustment']);
        foreach (['0', '100', '-1', '1e1', '100.00000000001'] as $invalid) {
            $body['gateways'][0]['presentation']['price_adjustment']['value'] = $invalid;
            $this->putJson('/api/v1/my-store/payments', $body, $headers)->assertUnprocessable();
            $this->assertSame($rule, $row->fresh()->presentation['price_adjustment']);
        }
    }

    private function owner(): array
    {
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class, StorePermissionsSeeder::class]);
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user)];
        $this->getJson('/api/v1/my-store', $headers)->assertOk();

        return [$user->fresh()->store, $headers];
    }

    public function test_all_source_methods_and_native_extras_have_logos_without_creating_settings_rows(): void
    {
        [$store, $headers] = $this->owner();
        $response = $this->getJson('/api/v1/my-store/payments', $headers)->assertOk()->assertJsonCount(23, 'data');
        $rows = collect($response->json('data'))->keyBy('slug');
        foreach ($rows as $slug => $row) {
            $this->assertStringStartsWith('/payments/'.$slug.'.', $row['logo_url']);
            $this->assertFalse($row['enabled']);
        }
        $this->assertFalse($rows['taager']['available']);
        $this->assertFalse($rows['fabmisr']['available']);
        $this->assertDatabaseMissing('store_payment_gateways', ['store_id' => $store->id]);
    }

    public function test_partial_updates_preserve_secrets_and_other_methods_and_reject_incomplete_activation(): void
    {
        [$store, $headers] = $this->owner();
        $stripe = StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'stripe', 'enabled' => false,
            'credentials' => ['secret_key' => 'private-synthetic', 'webhook_secret' => 'hook-synthetic', 'publishable_key' => 'pk_test_synthetic']]);
        $cod = StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'cod', 'enabled' => true]);
        $before = $cod->fresh()->getRawOriginal();
        $this->putJson('/api/v1/my-store/payments', ['gateways' => [['slug' => 'stripe', 'enabled' => true, 'credentials' => ['secret_key' => ''],
            'presentation' => ['name' => 'بطاقة بنكية', 'description' => 'دفع آمن'], 'order' => 8]]], $headers)->assertOk();
        $this->assertSame('private-synthetic', $stripe->fresh()->credentials['secret_key']);
        $this->assertSame('بطاقة بنكية', $stripe->fresh()->presentation['name']);
        $this->assertSame($before, $cod->fresh()->getRawOriginal());
        $rows = collect($this->getJson('/api/v1/my-store/payments', $headers)->assertOk()->json('data'))->keyBy('slug');
        $this->assertSame('********', $rows['stripe']['credentials']['secret_key']);
        $this->assertSame('pk_test_synthetic', $rows['stripe']['credentials']['publishable_key']);
        $this->putJson('/api/v1/my-store/payments', ['gateways' => [['slug' => 'paylink', 'enabled' => true, 'credentials' => ['api_key' => 'only-one-key']]]], $headers)->assertUnprocessable();
        $this->assertDatabaseMissing('store_payment_gateways', ['store_id' => $store->id, 'gateway' => 'paylink']);
        $this->putJson('/api/v1/my-store/payments', ['gateways' => [['slug' => 'taager', 'enabled' => true]]], $headers)->assertUnprocessable();
    }

    public function test_logo_assets_are_owned_images_and_invalid_batch_is_atomic(): void
    {
        [$store, $headers] = $this->owner();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other', 'currency' => 'USD']);
        $asset = StoreMediaAsset::create(['store_id' => $other->id, 'disk' => 'public', 'path' => 'stores/other.png', 'original_name' => 'other.png', 'mime' => 'image/png', 'size_bytes' => 100, 'checksum_sha256' => hash('sha256', 'other')]);
        $this->putJson('/api/v1/my-store/payments', ['gateways' => [['slug' => 'cod', 'enabled' => true, 'presentation' => ['logo_asset_id' => $asset->id]]]], $headers)->assertUnprocessable();
        $asset->update(['store_id' => $store->id, 'mime' => 'text/html']);
        $this->putJson('/api/v1/my-store/payments', ['gateways' => [['slug' => 'cod', 'enabled' => true], ['slug' => 'bank_transfer', 'enabled' => true, 'presentation' => ['logo_asset_id' => $asset->id]]]], $headers)->assertUnprocessable();
        $this->assertDatabaseMissing('store_payment_gateways', ['store_id' => $store->id]);
        $this->putJson('/api/v1/my-store/payments', ['gateways' => [['slug' => 'cod', 'enabled' => true, 'credentials' => ['unexpected_secret' => 'value']]]], $headers)->assertUnprocessable();
    }

    public function test_legacy_secrets_and_an_omitted_existing_environment_survive_presentation_updates(): void
    {
        [$store, $headers] = $this->owner();
        $store->update(['theme_settings' => ['payment_gateways' => [['slug' => 'stripe', 'enabled' => true, 'test_mode' => false,
            'credentials' => ['secret_key' => 'legacy-synthetic', 'webhook_secret' => 'legacy-hook']]]]]);
        $input = ['gateways' => [['slug' => 'stripe', 'enabled' => true, 'presentation' => ['name' => 'My cards']]]];
        $this->putJson('/api/v1/my-store/payments', $input, $headers)->assertOk();
        $setting = StorePaymentGateway::where('store_id', $store->id)->where('gateway', 'stripe')->firstOrFail();
        $this->assertSame('legacy-synthetic', $setting->credentials['secret_key']);
        $this->assertFalse($setting->test_mode);
        $this->putJson('/api/v1/my-store/payments', $input, $headers)->assertOk();
        $this->assertFalse($setting->fresh()->test_mode);
    }
}

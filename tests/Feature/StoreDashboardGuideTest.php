<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PlatformWalletPayment;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\PlatformWallet;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreDashboardGuideTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private array $auth;

    private string $url = '/api/v1/my-store/dashboard-guide';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner)];
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'slug' => 'guide', 'name' => 'Guide', 'status' => 'active']);
    }

    private function body(array $changes = []): array
    {
        return array_replace(['version' => 1, 'completed' => [], 'hidden_panels' => []], $changes);
    }

    public function test_read_derives_only_owned_setup_without_creating_preferences_wallet_or_history(): void
    {
        $otherOwner = User::factory()->create();
        $other = Store::create(['owner_user_id' => $otherOwner->id, 'owner_type' => 'merchant', 'slug' => 'other-guide', 'name' => 'Other', 'status' => 'active']);
        Product::create(['store_id' => $other->id, 'user_id' => $otherOwner->id, 'name' => 'Other product', 'slug' => 'other', 'price' => 100, 'is_active' => true]);
        Category::create(['store_id' => $other->id, 'name' => 'Other category', 'slug' => 'other']);
        StoreDomain::create(['store_id' => $other->id, 'host' => 'other.example.test', 'type' => 'custom', 'is_primary' => true, 'status' => 'verified', 'verified_at' => now()]);
        $read = $this->getJson($this->url, $this->auth)->assertOk()->assertJsonPath('data.percent', 0)->assertJsonPath('data.total', 10)->assertJsonPath('data.preferences.version', 1);
        $this->assertStringContainsString('no-store', $read->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $read->headers->get('Cache-Control'));
        $this->assertNull($this->store->fresh()->dashboard_preferences);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        $this->assertDatabaseCount('store_orders', 0);
        Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Own draft product', 'slug' => 'own', 'price' => 100, 'is_active' => false]);
        $this->store->update(['logo' => 'own-logo.png', 'font_family' => 'Cairo', 'primary_color' => '#123456', 'site_title' => 'Own site', 'description' => 'Own description', 'google_merchant_configuration' => ['address' => 'Own store address']]);
        $this->getJson($this->url, $this->auth)->assertJsonPath('data.percent', 60)->assertJsonPath('data.detected.products', true)->assertJsonPath('data.detected.categories', false)->assertJsonPath('data.detected.domain', false);
    }

    public function test_manual_acknowledgements_and_dismissal_preserve_actual_configuration_and_reject_stale_versions(): void
    {
        $first = $this->body(['completed' => ['wallet' => true]]);
        $this->putJson($this->url, $first, $this->auth)->assertOk()->assertJsonPath('data.percent', 10)->assertJsonPath('data.detected.wallet', false)->assertJsonPath('data.preferences.version', 2);
        $this->putJson($this->url, $first, $this->auth)->assertConflict();
        $this->getJson($this->url, $this->auth)->assertJsonPath('data.completed.wallet', true)->assertJsonPath('data.completed.products', false);
        Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'New product', 'slug' => 'new-product', 'price' => 50, 'is_active' => true]);
        $this->getJson($this->url, $this->auth)->assertJsonPath('data.percent', 20)->assertJsonPath('data.completed.products', true);
        $this->putJson($this->url, $this->body(['version' => 2, 'completed' => ['wallet' => true], 'hidden_panels' => ['setup', 'help']]), $this->auth)->assertOk()->assertJsonPath('data.preferences.hidden_panels', ['setup', 'help'])->assertJsonPath('data.percent', 20);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        $this->assertDatabaseCount('platform_wallet_payments', 0);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame('active', $this->store->fresh()->status);
        $this->assertNull($this->store->fresh()->logo);
    }

    public function test_validation_permissions_and_other_store_are_enforced(): void
    {
        foreach ([['completed' => ['unknown' => true]], ['completed' => ['wallet' => 'complete']], ['hidden_panels' => ['billing']], ['hidden_panels' => ['setup', 'setup']], ['version' => 0]] as $change) {
            $this->putJson($this->url, $this->body($change), $this->auth)->assertUnprocessable();
        }
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)];
        $this->getJson('/api/v1/stores/'.$this->store->id.'/dashboard-guide', $headers)->assertNotFound();
        $this->putJson('/api/v1/stores/'.$this->store->id.'/dashboard-guide', $this->body(), $headers)->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.analytics.view');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->url, $headers)->assertForbidden();
        $this->putJson($this->url, $this->body(), $headers)->assertForbidden();
        $employee->givePermissionTo('store.settings.manage');
        $this->travel(31)->seconds();
        $this->putJson($this->url, $this->body(['hidden_panels' => ['setup']]), $headers)->assertOk();
    }

    public function test_wallet_detection_requires_confirmed_real_funding_and_ignores_other_owners_and_test_payments(): void
    {
        $other = User::factory()->create();
        $funding = app(PlatformWallet::class)->funding($other, 1000, 'guide-other');
        $row = ['id' => (string) Str::uuid(), 'user_id' => $other->id, 'request_key' => (string) Str::uuid(), 'amount_milli' => 1000, 'currency' => 'USD', 'status' => 'paid', 'test_mode' => false, 'credentials' => ['api_key' => 'fixture', 'vendor_key' => 'fixture'], 'wallet_entry_id' => $funding->id];
        PlatformWalletPayment::create($row);
        $this->getJson($this->url, $this->auth)->assertJsonPath('data.detected.wallet', false);
        $funding = app(PlatformWallet::class)->funding($this->owner, 1000, 'guide-own');
        $payment = PlatformWalletPayment::create(array_replace($row, ['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'request_key' => (string) Str::uuid(), 'test_mode' => true, 'wallet_entry_id' => $funding->id]));
        $this->getJson($this->url, $this->auth)->assertJsonPath('data.detected.wallet', false);
        $payment->update(['test_mode' => false, 'status' => 'pending']);
        $this->getJson($this->url, $this->auth)->assertJsonPath('data.detected.wallet', false);
        $payment->update(['status' => 'paid']);
        $this->getJson($this->url, $this->auth)->assertJsonPath('data.detected.wallet', true)->assertJsonPath('data.percent', 10);
    }

    public function test_optional_official_links_exclude_unsafe_or_non_download_urls(): void
    {
        config(['merchant_dashboard.tutorials_url' => 'javascript:alert(1)', 'merchant_dashboard.android_url' => 'https://play.google.com/store/apps/details', 'merchant_dashboard.ios_url' => 'https://apps.apple.com/', 'merchant_dashboard.updates_url' => 'https://user:secret@example.test/updates']);
        $this->getJson($this->url, $this->auth)->assertJsonPath('data.resources', []);
        config(['merchant_dashboard.tutorials_url' => 'https://help.example.test/merchant-guide', 'merchant_dashboard.android_url' => 'https://play.google.com/store/apps/details?id=example.fixture.seller', 'merchant_dashboard.ios_url' => 'https://apps.apple.com/us/app/fixture/id123456789']);
        $this->getJson($this->url, $this->auth)->assertJsonPath('data.resources.tutorials_url', 'https://help.example.test/merchant-guide')->assertJsonPath('data.resources.android_url', 'https://play.google.com/store/apps/details?id=example.fixture.seller')->assertJsonPath('data.resources.ios_url', 'https://apps.apple.com/us/app/fixture/id123456789');
    }
}

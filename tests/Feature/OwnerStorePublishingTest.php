<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Scopes\ProductScope;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner-side publishing through /my-store: readiness, the readiness gate on
 * publish, unpublish, outbox events, and store isolation on /stores/{store}.
 */
class OwnerStorePublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $this->seed(StorePermissionsSeeder::class);
        // Registered before any store is provisioned so the default theme is
        // installed + activated automatically (mirrors production bootstrap).
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/default/theme.json'));
    }

    private function owner(string $role = 'Supplier'): User
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

    private function addActiveProduct(Store $store): void
    {
        Product::query()->withoutGlobalScope(ProductScope::class)->create([
            'store_id' => $store->id,
            'user_id' => $store->owner_user_id,
            'name' => 'Launch Product',
            'slug' => 'launch-product-'.$store->id,
            'price' => 100,
            'is_active' => true,
        ]);
    }

    public function test_readiness_reports_each_check_for_the_owners_store(): void
    {
        $owner = $this->owner();
        $this->provisionedStore($owner);

        $this->asUser($owner)->getJson('/api/v1/my-store/readiness')
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.checks.profile', true)
            ->assertJsonPath('data.checks.verified_primary_domain', true)
            ->assertJsonPath('data.checks.active_theme', true)
            ->assertJsonPath('data.checks.active_product', false);
    }

    public function test_publish_is_gated_on_readiness_then_succeeds(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $this->assertSame('draft', $store->status);

        $this->asUser($owner)->postJson('/api/v1/my-store/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store']);
        $this->assertSame('draft', $store->fresh()->status);
        $this->assertDatabaseMissing('outbox_messages', ['event_type' => 'StorePublished']);

        $this->addActiveProduct($store);

        $this->asUser($owner)->getJson('/api/v1/my-store/readiness')
            ->assertOk()
            ->assertJsonPath('data.ready', true);

        $this->asUser($owner)->postJson('/api/v1/my-store/publish')
            ->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.status', 'active');
        $this->assertSame('active', $store->fresh()->status);
        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'StorePublished',
            'aggregate_type' => 'store',
            'aggregate_id' => (string) $store->id,
        ]);
    }

    public function test_unpublish_returns_the_store_to_draft(): void
    {
        $owner = $this->owner('Merchant');
        $store = $this->provisionedStore($owner);
        $this->addActiveProduct($store);
        $this->asUser($owner)->postJson('/api/v1/my-store/publish')->assertOk();

        $this->asUser($owner)->postJson('/api/v1/my-store/unpublish')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
        $this->assertSame('draft', $store->fresh()->status);
        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'StoreUnpublished',
            'aggregate_id' => (string) $store->id,
        ]);
    }

    public function test_owner_cannot_publish_or_read_a_foreign_store(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        $storeB = $this->provisionedStore($b);
        $this->provisionedStore($a);

        $this->asUser($a)->getJson("/api/v1/stores/{$storeB->id}/readiness")->assertForbidden();
        $this->asUser($a)->postJson("/api/v1/stores/{$storeB->id}/publish")->assertForbidden();
        $this->asUser($a)->postJson("/api/v1/stores/{$storeB->id}/unpublish")->assertForbidden();
        $this->assertSame('draft', $storeB->fresh()->status);
    }

    public function test_admin_can_publish_any_store_by_id(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $this->addActiveProduct($store);
        $admin = $this->owner('Admin');

        $this->asUser($admin)->getJson("/api/v1/stores/{$store->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', true);
        $this->asUser($admin)->postJson("/api/v1/stores/{$store->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }
}

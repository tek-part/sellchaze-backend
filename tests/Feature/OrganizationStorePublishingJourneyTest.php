<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Scopes\ProductScope;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\ThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationStorePublishingJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_store_is_provisioned_then_published_only_when_ready(): void
    {
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/default/theme.json'));
        $owner = User::factory()->create([
            'is_active' => true,
            'pending_approval' => false,
        ]);
        $organization = Organization::query()->create(['name' => 'Launch Company', 'slug' => 'launch-company']);
        $organization->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($owner));

        $created = $this->postJson("/api/v2/organizations/{$organization->id}/stores", [
            'name' => 'Launch Store',
            'slug' => 'launch-store',
            'currency' => 'EGP',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft');
        $storeId = $created->json('data.id');
        $store = Store::query()->findOrFail($storeId);
        $host = 'http://'.$store->slug.'.'.config('sellchase.storefront.base_domain');

        $this->assertDatabaseHas('store_domains', [
            'store_id' => $storeId,
            'type' => 'subdomain',
            'status' => 'verified',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('store_themes', ['store_id' => $storeId, 'status' => 'active']);
        $this->getJson("/api/v2/organizations/{$organization->id}/stores/{$storeId}/readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.checks.active_product', false);
        $this->postJson("/api/v2/organizations/{$organization->id}/stores/{$storeId}/publish")
            ->assertUnprocessable();
        $this->getJson($host.'/api/v1/storefront/resolve')->assertNotFound();

        Product::query()->withoutGlobalScope(ProductScope::class)->create([
            'store_id' => $storeId,
            'user_id' => $owner->id,
            'name' => 'Launch Product',
            'slug' => 'launch-product',
            'price' => 100,
            'is_active' => true,
        ]);

        $this->getJson("/api/v2/organizations/{$organization->id}/stores/{$storeId}/readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', true);
        $this->postJson("/api/v2/organizations/{$organization->id}/stores/{$storeId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->getJson($host.'/api/v1/storefront/resolve')
            ->assertOk()
            ->assertJsonPath('data.slug', 'launch-store');
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'StorePublished']);

        $this->postJson("/api/v2/organizations/{$organization->id}/stores/{$storeId}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
        $this->getJson($host.'/api/v1/storefront/resolve')->assertNotFound();
    }
}

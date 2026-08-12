<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpandedV2ResourcesTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $organization = Organization::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $organization->memberships()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $store = Store::query()->create([
            'organization_id' => $organization->id, 'owner_user_id' => $user->id,
            'owner_type' => 'merchant', 'name' => ucfirst($slug).' Store', 'slug' => $slug, 'currency' => 'USD',
        ]);

        return [$user, $organization, $store];
    }

    public function test_company_post_route_forces_the_path_organization_identity(): void
    {
        [$user, $organization] = $this->company('publisher');

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user))
            ->postJson("/api/v2/organizations/{$organization->id}/posts", [
                'type' => 'update_news', 'body' => 'Production capacity available',
                'acting_organization_id' => 999999,
            ])->assertCreated()->assertJsonPath('data.acting_as.id', $organization->id);
    }

    public function test_page_drafts_are_tenant_scoped_through_organization_and_store(): void
    {
        [$user, $organization, $store] = $this->company('alpha-v2');
        [, $otherOrganization, $otherStore] = $this->company('beta-v2');
        $auth = $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));

        $auth->getJson("/api/v2/organizations/{$organization->id}/stores/{$store->id}/page-drafts")
            ->assertOk()->assertJsonCount(0, 'data');
        $auth->getJson("/api/v2/organizations/{$organization->id}/stores/{$otherStore->id}/page-drafts")
            ->assertNotFound();
        $auth->getJson("/api/v2/organizations/{$otherOrganization->id}/stores/{$otherStore->id}/page-drafts")
            ->assertForbidden();
    }
}

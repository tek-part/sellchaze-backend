<?php

namespace Tests\Feature;

use App\Actions\Organizations\OnboardSelfServiceAccountAction;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SelfServiceOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_service_registration_provisions_company_primary_store_domain_and_theme(): void
    {
        $this->seed(ThemeSeeder::class);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Mona Owner',
            'email' => 'mona@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'registration_role' => 'Merchant',
            'company_name' => 'Mona Trading',
            'store_name' => 'Mona Market',
        ])->assertCreated()
            ->assertJsonPath('pending_approval', false)
            ->assertJsonPath('onboarding.organization_id', 1)
            ->assertJsonPath('onboarding.store_id', 1)
            ->assertJsonPath('onboarding.next', '/stores/1/onboarding?organization=1');

        $user = User::query()->where('email', 'mona@example.com')->firstOrFail();
        $organization = Organization::query()->firstOrFail();
        $store = Store::query()->firstOrFail();
        $this->assertSame('Mona Trading', $organization->name);
        $this->assertSame($organization->id, $store->organization_id);
        $this->assertSame('Mona Market', $store->name);
        $this->assertTrue($store->is_primary);
        $this->assertSame('draft', $store->status);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('store_domains', ['store_id' => $store->id, 'type' => 'subdomain']);
        $this->assertDatabaseHas('store_themes', ['store_id' => $store->id, 'status' => 'active']);

        $this->withHeader('Authorization', 'Bearer '.$response->json('access_token'))
            ->getJson('/api/v2/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $organization->id);
    }

    public function test_onboarding_failure_rolls_back_the_account_and_tenant_graph(): void
    {
        $this->mock(OnboardSelfServiceAccountAction::class)
            ->shouldReceive('execute')
            ->once()
            ->andThrow(new RuntimeException('provisioning failed'));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Rollback Owner',
            'email' => 'rollback@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'registration_role' => 'Supplier',
        ])->assertStatus(500);

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('stores', 0);
    }
}

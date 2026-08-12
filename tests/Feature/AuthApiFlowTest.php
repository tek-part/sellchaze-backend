<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(StorePermissionsSeeder::class);
    }

    public function test_protected_bootstrap_endpoints_require_a_bearer_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/my-store')->assertUnauthorized();
    }

    public function test_valid_access_token_bootstraps_user_dashboard_and_store(): void
    {
        $merchant = User::factory()->create([
            'is_active' => true,
            'pending_approval' => false,
        ]);
        $merchant->assignRole('Merchant');
        $token = JwtTokenService::fromConfig()->issueAccessToken($merchant);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $merchant->id);

        $this->withToken($token)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard_mode', 'merchant');

        $this->withToken($token)
            ->getJson('/api/v1/my-store')
            ->assertOk()
            ->assertJsonPath('data.owner_user_id', $merchant->id)
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_refresh_token_rotation_returns_a_working_access_token(): void
    {
        $merchant = User::factory()->create([
            'is_active' => true,
            'pending_approval' => false,
        ]);
        $merchant->assignRole('Merchant');
        $jwt = JwtTokenService::fromConfig();
        $refresh = $jwt->issueRefreshToken($merchant);

        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refresh,
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $this->withToken($response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $merchant->id);
    }
}

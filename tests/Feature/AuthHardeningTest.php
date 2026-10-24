<?php

namespace Tests\Feature;

use App\Models\AuthSession;
use App\Models\User;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        config(['services.google.client_id' => 'cid']);
    }

    private function google(array $payload): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response($payload + ['aud' => 'cid', 'sub' => 'g-1', 'name' => 'B', 'email_verified' => 'true'])]);
    }

    public function test_an_admin_deleted_account_is_not_restored_by_google_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'banned@example.test', 'is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $user->delete();
        $this->google(['email' => 'banned@example.test']);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'x'])->assertForbidden()->assertJsonPath('code', 'account_deleted');
        $this->assertTrue($user->fresh()->trashed());
    }

    public function test_google_requires_an_id_token_and_a_verified_email(): void
    {
        $this->postJson('/api/v1/auth/google', ['access_token' => 'foreign-app-token'])->assertUnprocessable()->assertJsonValidationErrors('id_token');
        $this->google(['email' => 'new@example.test', 'email_verified' => 'false']);
        $this->postJson('/api/v1/auth/google', ['id_token' => 'x', 'registration_role' => 'Merchant'])->assertUnprocessable()->assertJsonValidationErrors('id_token');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_changing_the_password_ends_other_sessions_and_reset_ends_all(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPass#2026'), 'is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'OldPass#2026'])->assertOk()->json('access_token');
        $current = $login();
        $other = $login();
        $this->assertSame(2, AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->count());

        $this->withToken($current)->putJson('/api/v1/auth/password', ['current_password' => 'OldPass#2026', 'password' => 'NewPass#2026', 'password_confirmation' => 'NewPass#2026'])->assertOk();
        $this->assertSame(1, AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($current)->getJson('/api/v1/auth/me')->assertOk();

        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Reset#2026pw', 'password_confirmation' => 'Reset#2026pw'])->assertOk();
        $this->assertSame(0, AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_login_is_throttled_per_account(): void
    {
        $user = User::factory()->create(['email' => 'target@example.test']);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'target@example.test', 'password' => 'wrong-'.$i]);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'target@example.test', 'password' => 'wrong'])->assertStatus(429);
        $this->postJson('/api/v1/auth/login', ['email' => 'someone-else@example.test', 'password' => 'wrong'])->assertStatus(422);
    }
}

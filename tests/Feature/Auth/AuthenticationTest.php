<?php

namespace Tests\Feature\Auth;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_spa_login_route_redirects_to_the_configured_frontend(): void
    {
        $this->get('/login')->assertRedirect(config('sellchase.frontend_url').'/login');
    }

    public function test_active_user_can_authenticate_through_the_json_api(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('StrongPass123!'),
            'is_active' => true,
            'pending_approval' => false,
        ]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'StrongPass123!'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in', 'user']);
    }

    public function test_invalid_password_and_pending_account_are_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('StrongPass123!'), 'pending_approval' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable();

        $user->update(['pending_approval' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'StrongPass123!'])
            ->assertUnprocessable();
    }

    public function test_authenticated_reads_throttle_session_telemetry_writes(): void
    {
        Carbon::setTestNow('2026-08-12 10:00:00');
        $user = User::factory()->create([
            'password' => Hash::make('StrongPass123!'),
            'is_active' => true,
            'pending_approval' => false,
        ]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'StrongPass123!',
        ])->assertOk()->json('access_token');

        Carbon::setTestNow('2026-08-12 10:01:00');
        $this->withToken($token)->getJson('/api/v1/notifications?per_page=1')->assertOk();
        $firstTouch = AuthSession::query()->firstOrFail()->last_used_at;

        Carbon::setTestNow('2026-08-12 10:02:00');
        $this->withToken($token)->getJson('/api/v1/notifications?per_page=1')->assertOk();
        $this->assertTrue($firstTouch->equalTo(AuthSession::query()->firstOrFail()->last_used_at));
        Carbon::setTestNow();
    }
}

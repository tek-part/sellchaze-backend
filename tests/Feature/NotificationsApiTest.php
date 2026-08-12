<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_all_read_is_bulk_and_idempotent(): void
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        foreach (range(1, 3) as $index) {
            DatabaseNotification::query()->create([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => ['index' => $index],
            ]);
        }
        $token = JwtTokenService::fromConfig()->issueAccessToken($user);

        $this->withToken($token)->postJson('/api/v1/notifications/read-all')
            ->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame(0, $user->unreadNotifications()->count());

        $this->withToken($token)->postJson('/api/v1/notifications/read-all')
            ->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame(3, $user->notifications()->whereNotNull('read_at')->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_status_notifications_are_transactional_and_sent_only_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Notification test', 'slug' => 'notification-test', 'status' => 'active']);
        app(CurrentStore::class)->set($store);
        $order = StoreOrder::create(['store_id' => $store->id, 'order_number' => 'ORD-NOTIFY', 'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test', 'status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'cod', 'currency' => 'EGP', 'subtotal' => 10, 'grand_total' => 10]);
        DB::beginTransaction();
        app(StoreOrderService::class)->transition($order, 'confirmed');
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        DB::rollBack();
        $this->assertSame(0, $owner->notifications()->count());
        app(StoreOrderService::class)->transition($order->fresh(), 'confirmed');
        $notification = $owner->notifications()->firstOrFail();
        $this->assertSame('/store/orders/'.$order->id, $notification->data['url']);
        $this->assertSame('confirmed', $notification->data['status']);
        $this->assertArrayNotHasKey('customer_email', $notification->data);
    }

    public function test_notification_feed_filters_and_actions_are_account_scoped(): void
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other = User::factory()->create();
        $make = fn ($owner, $read) => DatabaseNotification::query()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $owner->id, 'data' => ['title' => 'Alert', 'message' => 'Private'], 'read_at' => $read ? now() : null]);
        $unread = $make($user, false);
        $make($user, true);
        $foreign = $make($other, false);
        $token = JwtTokenService::fromConfig()->issueAccessToken($user);
        $this->withToken($token)->getJson('/api/v1/notifications?status=unread')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.id', $unread->id);
        $this->withToken($token)->getJson('/api/v1/notifications?status=read')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('unread_count', 1);
        $this->withToken($token)->getJson('/api/v1/notifications?status=unknown')->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/notifications/'.$foreign->id.'/read')->assertNotFound();
        foreach (range(1, 2) as $repeat) {
            $this->withToken($token)->postJson('/api/v1/notifications/'.$unread->id.'/read')->assertOk()->assertJsonPath('unread_count', 0);
        }
        $this->assertNull($foreign->fresh()->read_at);
        $this->withToken($token)->getJson('/api/v1/notifications?status=read')->assertOk()->assertJsonPath('meta.total', 2);
    }

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

<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Notifications\DomainDisabledNotification;
use App\Notifications\DomainPrimaryChangedNotification;
use App\Notifications\DomainSslExpiringNotification;
use App\Notifications\DomainSslFailedNotification;
use App\Notifications\DomainSslIssuedNotification;
use App\Notifications\DomainVerificationFailedNotification;
use App\Notifications\DomainVerifiedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainNotificationSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_domain_notification_survives_the_queue_round_trip(): void
    {
        $owner = User::factory()->create();
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Shop', 'slug' => 'shop-ser', 'status' => 'active']);
        $domain = StoreDomain::create(['store_id' => $store->id, 'host' => 'shop.example.test', 'type' => 'custom', 'is_primary' => false]);
        foreach ([
            new DomainSslExpiringNotification($domain, 5),
            new DomainSslFailedNotification($domain, 'dns'),
            new DomainSslIssuedNotification($domain, true),
            new DomainDisabledNotification($domain, 'expired'),
            new DomainVerificationFailedNotification($domain, 'txt missing'),
            new DomainPrimaryChangedNotification($domain, 'old.example.test'),
            new DomainVerifiedNotification($domain),
        ] as $notification) {
            $restored = unserialize(serialize($notification));
            $this->assertSame('shop.example.test', $restored->domain->host);
            $this->assertNotSame('', $restored->subject());
        }
    }
}

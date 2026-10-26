<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreSocialProofAppTest extends TestCase
{
    use MerchantAppFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    public function test_settings_are_versioned_validated_and_disabled_by_default(): void
    {
        $settings = $this->settings('social-proof');
        $this->assertFalse($settings['enabled']);
        $this->assertSame(1, $settings['version']);
        $this->getJson('/api/v1/my-store/apps/social-proof/settings', $this->auth)->assertJsonPath('meta.viewers_available', false);
        $this->order();
        $this->getJson($this->storefront.'/social-proof')->assertOk()->assertJsonPath('data', null);
        $this->getJson($this->storefront.'/context')->assertOk()->assertJsonPath('store.social_proof_enabled', false);

        $body = array_replace($settings, ['enabled' => true, 'interval_seconds' => 30, 'position' => 'bottom-end', 'pages' => ['home' => true, 'product' => true, 'collection' => false, 'cart' => true]]);
        $this->putJson('/api/v1/my-store/apps/social-proof/settings', $body, $this->auth)->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.position', 'bottom-end')->assertJsonPath('data.pages.collection', false);
        $this->putJson('/api/v1/my-store/apps/social-proof/settings', $body, $this->auth)->assertConflict();
        foreach ([['interval_seconds' => 1], ['position' => 'top'], ['lookback_days' => 0], ['max_events' => 21], ['pages' => ['checkout' => true]], ['enabled' => 'yes']] as $change) {
            $this->putJson('/api/v1/my-store/apps/social-proof/settings', array_replace($body, ['version' => 2], $change), $this->auth)->assertUnprocessable();
        }
        $this->getJson($this->storefront.'/context')->assertJsonPath('store.social_proof_enabled', true);
        $this->assertSame(2, $this->store->fresh()->social_proof_configuration['version']);
    }

    public function test_feed_uses_real_recent_orders_and_never_exposes_contact_details(): void
    {
        $this->saveSettings('social-proof', ['enabled' => true, 'lookback_days' => 3])->assertOk();
        $this->order(['customer_name' => 'mona Ali Hassan', 'placed_at' => now()->subMinutes(12)]);
        $this->order(['customer_name' => '  سارة محمد', 'shipping_address' => ['line1' => 'Villa 3', 'city' => 'الإسكندرية'], 'placed_at' => now()->subHours(2)]);
        $this->order(['customer_name' => 'Omar', 'shipping_address' => ['line1' => 'x', 'city' => '12 Tahrir Street, flat 4'], 'placed_at' => now()->subHour()]);
        $this->order(['customer_name' => 'Cancelled Person', 'status' => 'cancelled']);
        $this->order(['customer_name' => 'Manual Person', 'manual_request_key' => 'manual-1']);
        $this->order(['customer_name' => 'Old Person', 'placed_at' => now()->subDays(4)]);
        $hidden = $this->makeProduct('Secret product', 50, attributes: ['catalog_hidden' => true]);
        $this->order(['customer_name' => 'Hidden Person'], product: $hidden);
        $otherOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other = Store::create(['owner_user_id' => $otherOwner->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-apps', 'currency' => 'EGP', 'status' => 'active']);
        $this->order(['customer_name' => 'Zed Other'], $other, $this->makeProduct('Other store item', 10, $other));

        $response = $this->getJson($this->storefront.'/social-proof')->assertOk();
        $events = $response->json('data.events');
        $this->assertCount(3, $events);
        $this->assertSame(['initial' => 'M', 'city' => 'Cairo'], array_intersect_key($events[0], ['initial' => 1, 'city' => 1]));
        $this->assertSame(12, $events[0]['minutes_ago']);
        $this->assertSame('Leather bag', $events[0]['product']['name']);
        $this->assertSame(['O', null], [$events[1]['initial'], $events[1]['city']]);
        $this->assertSame(['س', 'الإسكندرية'], [$events[2]['initial'], $events[2]['city']]);
        $body = $response->getContent();
        foreach (['01001234567', '+201001234567', 'mona@example.test', 'Ali', 'Hassan', 'Street', 'Villa', 'Tahrir', 'Cancelled', 'Manual', 'Old Person', 'Secret', 'Zed', 'Other store item', 'customer'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }

        Cache::flush();
        $this->saveSettings('social-proof', ['show_initial' => false, 'show_city' => false, 'show_time' => false])->assertOk();
        $event = $this->getJson($this->storefront.'/social-proof')->json('data.events.0');
        $this->assertSame([null, null, null], [$event['initial'], $event['city'], $event['minutes_ago']]);
    }

    public function test_disabling_removes_the_feed_and_settings_are_isolated_and_permissioned(): void
    {
        $this->saveSettings('social-proof', ['enabled' => true])->assertOk();
        $this->order();
        $this->assertCount(1, $this->getJson($this->storefront.'/social-proof')->json('data.events'));
        $this->saveSettings('social-proof', ['enabled' => false])->assertOk();
        $this->getJson($this->storefront.'/social-proof')->assertJsonPath('data', null);

        $this->getJson('/api/v1/my-store/apps/social-proof/settings', $this->employee([]))->assertForbidden();
        $this->getJson('/api/v1/my-store/apps/social-proof/settings', $this->employee(['store.view', 'store.settings.manage']))->assertOk();
        $stranger = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $stranger->assignRole('Merchant');
        $this->assertContains($this->getJson('/api/v1/stores/'.$this->store->id.'/apps/social-proof/settings', $this->headersFor($stranger))->status(), [403, 404]);
        $this->assertContains($this->putJson('/api/v1/stores/'.$this->store->id.'/apps/social-proof/settings', ['version' => 3, 'enabled' => true], $this->headersFor($stranger))->status(), [403, 404]);
        $this->getJson('/api/v1/my-store/apps/cloaking/settings', $this->auth)->assertNotFound();
        $this->assertSame(0, DB::table('stores')->whereNotNull('country_restriction_configuration')->count());
    }
}

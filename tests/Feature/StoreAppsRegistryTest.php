<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreAppsRegistryTest extends TestCase
{
    use MerchantAppFixtures, RefreshDatabase;

    private const BUILT = ['social-proof' => 'apps/social-proof/settings', 'country-restriction' => 'apps/country-restriction/settings',
        'fake-orders' => 'apps/fake-orders/settings', 'bundles' => 'apps/bundles/settings', 'cross-selling' => 'apps/bundles/settings', 'team' => 'settings/managers'];

    private const COMING_SOON = ['splendapp', 'whatsapp-official', 'whatsapp-unofficial', 'ai', 'angezny', 'rimonda', 'taager', 'top-media-buyer', 'wesell', 'shopify', 'woocommerce', 'catalog', 'branding'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    public function test_cloaking_is_removed_and_unbuilt_integrations_are_honestly_coming_soon(): void
    {
        $response = $this->getJson('/api/v1/my-store/apps', $this->auth)->assertOk();
        $apps = collect($response->json('data'))->keyBy('key');
        $this->assertFalse($apps->has('cloaking'));
        $this->assertStringNotContainsString('cloaking', strtolower($response->getContent()));
        foreach (self::BUILT as $key => $route) {
            $this->assertTrue($apps[$key]['available'] && $apps[$key]['can_manage'] && ! $apps[$key]['coming_soon'], $key);
            $this->assertSame($route, $apps[$key]['route'], $key);
        }
        foreach (self::COMING_SOON as $key) {
            $this->assertSame([false, true, false, null], [$apps[$key]['available'], $apps[$key]['coming_soon'], $apps[$key]['can_manage'], $apps[$key]['route']], $key);
        }
        $this->assertSame(count(self::BUILT) + count(self::COMING_SOON) + 12, $apps->count());
    }

    public function test_configured_flags_follow_saved_settings_and_team_is_owner_only(): void
    {
        $apps = fn (array $headers) => collect($this->getJson('/api/v1/my-store/apps', $headers)->assertOk()->json('data'))->keyBy('key');
        $this->assertFalse($apps($this->auth)['social-proof']['configured']);
        $this->saveSettings('social-proof', ['enabled' => true])->assertOk();
        $this->saveSettings('country-restriction', ['enabled' => true, 'countries' => ['EG']])->assertOk();
        $this->saveSettings('fake-orders', ['enabled' => true, 'rules' => ['high_quantity' => ['enabled' => true]]])->assertOk();
        $owner = $apps($this->auth);
        $this->assertTrue($owner['social-proof']['configured'] && $owner['country-restriction']['configured'] && $owner['fake-orders']['configured']);
        $this->assertFalse($owner['bundles']['configured']);
        $this->assertFalse($owner['team']['configured']);

        $employee = $apps($this->employee(['store.view', 'store.settings.manage']));
        $this->assertTrue($employee['social-proof']['can_manage']);
        $this->assertFalse($employee['team']['can_manage']);
        $this->assertTrue($employee['team']['missing_permission']);
        $this->assertFalse($employee['bundles']['can_manage']);
    }
}

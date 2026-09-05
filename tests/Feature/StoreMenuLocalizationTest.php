<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreMenuItem;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** E4: menu item labels accept string|{locale: string}; storefront trees pick by locale. */
class StoreMenuLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create([
            'owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar'],
        ]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    private function api(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }

    public function test_labels_are_stored_both_ways_and_picked_by_locale(): void
    {
        $this->api()->putJson("/api/v1/stores/{$this->store->id}/menus/header", [
            'name' => 'Header',
            'items' => [
                ['label' => ['en' => 'Shop', 'ar' => 'تسوق'], 'type' => 'internal', 'target' => 'products', 'children' => [
                    ['label' => ['ar' => 'أحذية'], 'type' => 'category', 'target' => 'shoes'],
                ]],
                ['label' => 'Legacy', 'type' => 'url', 'target' => 'https://x.test'],
            ],
        ])->assertOk()
            ->assertJsonPath('items.0.label', 'Shop')
            ->assertJsonPath('items.0.label_i18n.ar', 'تسوق')
            ->assertJsonPath('items.1.label', 'Legacy')
            ->assertJsonPath('items.1.label_i18n.en', 'Legacy');

        $rows = StoreMenuItem::withoutGlobalScopes()->where('store_id', $this->store->id)->orderBy('id')->get();
        $this->assertSame('Shop', $rows[0]->label);
        $this->assertSame(['en' => 'Shop', 'ar' => 'تسوق'], $rows[0]->label_i18n);
        $this->assertSame('أحذية', $rows[1]->label);          // only Arabic given → first non-empty
        $this->assertNull($rows[2]->label_i18n);              // legacy string keeps the column null

        $this->getJson('http://nike.sellchase.com/api/v1/storefront?lang=ar')
            ->assertOk()
            ->assertJsonPath('navigation.header.0.label', 'تسوق')
            ->assertJsonPath('navigation.header.0.children.0.label', 'أحذية')
            ->assertJsonPath('navigation.header.1.label', 'Legacy')
            ->assertJsonPath('navigation.footer', []);

        $this->getJson('http://nike.sellchase.com/api/v1/storefront')
            ->assertJsonPath('navigation.header.0.label', 'Shop')
            ->assertJsonPath('navigation.header.0.children.0.label', 'أحذية'); // no English → fallback to any

        $this->getJson('http://nike.sellchase.com/api/v1/storefront/context?template=home&lang=ar')
            ->assertOk()->assertJsonPath('navigation.header.0.label', 'تسوق');
    }

    public function test_invalid_labels_are_rejected(): void
    {
        $base = "/api/v1/stores/{$this->store->id}/menus/header";
        $this->api()->putJson($base, ['name' => 'H', 'items' => [['label' => ['fr' => 'Non']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.label']);
        $this->api()->putJson($base, ['name' => 'H', 'items' => [['label' => ['en' => '']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.label']);
        $this->api()->putJson($base, ['name' => 'H', 'items' => [['label' => 42]]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.label']);
        $this->api()->putJson($base, ['name' => 'H', 'items' => [['label' => 'Ok', 'children' => [['label' => ['xx' => 'bad']]]]]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.children.0.label']);
    }
}

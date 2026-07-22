<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreMenu;
use App\Models\StorePage;
use App\Models\StoreReusableSection;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageBuilderMoreTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/default/theme.json'));
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike', 'currency' => 'USD', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($this->store);
    }

    private function api(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }

    private function base(): string
    {
        return "/api/v1/stores/{$this->store->id}";
    }

    public function test_publish_sets_published_at_and_public_flag(): void
    {
        $id = $this->api()->postJson($this->base().'/pages', ['title' => 'P', 'slug' => 'p'])->json('data.id');
        $this->assertNull(StorePage::find($id)->published_at);

        $res = $this->api()->postJson($this->base()."/pages/{$id}/publish")->assertOk();
        $this->assertSame('published', $res->json('data.status'));
        $this->assertTrue($res->json('data.is_public'));
        $this->assertNotNull(StorePage::find($id)->published_at);
    }

    public function test_page_show_returns_ordered_sections(): void
    {
        $id = $this->api()->postJson($this->base().'/pages', ['title' => 'S', 'slug' => 's'])->json('data.id');
        $this->api()->putJson($this->base()."/pages/{$id}/sections", ['sections' => [
            ['type' => 'product-grid'], ['type' => 'hero'], ['type' => 'rich-text'],
        ]]);

        $data = $this->api()->getJson($this->base()."/pages/{$id}")->assertOk()->json('data');
        $this->assertCount(3, $data['sections']);
        $this->assertSame('product-grid', $data['sections'][0]['type']);
        $this->assertSame(0, $data['sections'][0]['position']);
        $this->assertSame('hero', $data['sections'][1]['type']);
        $this->assertSame('rich-text', $data['sections'][2]['type']);
    }

    public function test_menu_and_reusable_delete(): void
    {
        $this->api()->putJson($this->base().'/menus/header', ['name' => 'H', 'items' => []])->assertOk();
        $this->api()->deleteJson($this->base().'/menus/header')->assertOk();
        $this->assertSame(0, StoreMenu::where('store_id', $this->store->id)->count());

        $rid = $this->api()->postJson($this->base().'/reusable-sections', ['key' => 'r', 'name' => 'R', 'type' => 'hero', 'settings' => []])->json('data.id');
        $this->api()->deleteJson($this->base()."/reusable-sections/{$rid}")->assertOk();
        $this->assertSame(0, StoreReusableSection::where('store_id', $this->store->id)->count());
    }

    public function test_reusable_settings_override_by_page_section(): void
    {
        $rid = $this->api()->postJson($this->base().'/reusable-sections', ['key' => 'h', 'name' => 'H', 'type' => 'hero', 'settings' => ['headline' => 'Global', 'show_tagline' => true]])->json('data.id');
        $pid = $this->api()->postJson($this->base().'/pages', ['title' => 'O', 'slug' => 'ovr'])->json('data.id');
        // page section overrides the reusable's headline
        $this->api()->putJson($this->base()."/pages/{$pid}/sections", ['sections' => [
            ['type' => 'hero', 'reusable_section_id' => $rid, 'settings' => ['headline' => 'Local']],
        ]]);
        $this->api()->postJson($this->base()."/pages/{$pid}/publish");

        $html = $this->get('http://nike.sellchase.com/pages/ovr')->assertOk()->getContent();
        $this->assertStringContainsString('Local', $html);   // override wins
        $this->assertStringNotContainsString('Global', $html);
    }

    public function test_invalid_page_input_is_rejected(): void
    {
        $this->api()->postJson($this->base().'/pages', [])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->api()->postJson($this->base().'/pages', ['title' => 'X', 'slug' => 'Bad Slug!'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->api()->postJson($this->base().'/pages', ['title' => 'X', 'template' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('template');
    }
}

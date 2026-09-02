<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageBuilderExtrasTest extends TestCase
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

    // ---- Reusable sections (Task 3) ----

    public function test_reusable_section_edit_affects_all_pages(): void
    {
        $reusableId = $this->api()->postJson($this->base().'/reusable-sections', [
            'key' => 'promo-hero', 'name' => 'Promo Hero', 'type' => 'hero', 'settings' => ['headline' => 'Original'],
        ])->assertCreated()->json('data.id');

        $pageId = $this->api()->postJson($this->base().'/pages', ['title' => 'Landing', 'slug' => 'landing'])->json('data.id');
        $this->api()->putJson($this->base()."/pages/{$pageId}/sections", ['sections' => [
            ['type' => 'hero', 'reusable_section_id' => $reusableId],
        ]])->assertOk();
        $this->api()->postJson($this->base()."/pages/{$pageId}/publish")->assertOk();

        $this->assertStringContainsString('Original', $this->get('http://nike.sellchase.com/pages/landing')->getContent());

        // Published pages are immutable snapshots: editing the reusable draft
        // does not affect visitors until the page is explicitly republished.
        $this->api()->putJson($this->base()."/reusable-sections/{$reusableId}", ['settings' => ['headline' => 'Updated']])->assertOk();
        $this->assertStringContainsString('Original', $this->get('http://nike.sellchase.com/pages/landing')->getContent());
        $this->assertStringNotContainsString('Updated', $this->get('http://nike.sellchase.com/pages/landing')->getContent());

        $this->api()->postJson($this->base()."/pages/{$pageId}/publish")->assertOk();
        $this->assertStringContainsString('Updated', $this->get('http://nike.sellchase.com/pages/landing')->getContent());
    }

    // ---- Menus (Tasks 4 & 5) ----

    public function test_nested_typed_menu_resolves_urls(): void
    {
        $res = $this->api()->putJson($this->base().'/menus/header', [
            'name' => 'Main menu',
            'items' => [
                ['label' => 'Home', 'type' => 'url', 'target' => '/'],
                ['label' => 'Shop', 'type' => 'category', 'target' => 'shoes', 'children' => [
                    ['label' => 'Air Max', 'type' => 'product', 'target' => 'air-max'],
                    ['label' => 'About', 'type' => 'internal', 'target' => 'pages/about'],
                ]],
            ],
        ])->assertOk();

        $items = $res->json('items');
        $this->assertSame('Home', $items[0]['label']);
        $this->assertSame('/categories/shoes', $items[1]['url']);
        $this->assertCount(2, $items[1]['children']);
        $this->assertSame('/products/air-max', $items[1]['children'][0]['url']);
        $this->assertSame('/pages/about', $items[1]['children'][1]['url']);
    }

    public function test_header_menu_renders_in_storefront(): void
    {
        $this->api()->putJson($this->base().'/menus/header', [
            'name' => 'Main', 'items' => [['label' => 'Our Story', 'type' => 'url', 'target' => '/pages/story']],
        ])->assertOk();

        $html = $this->get('http://nike.sellchase.com/')->assertOk()->getContent();
        $this->assertStringContainsString('Our Story', $html);
        $this->assertStringContainsString('/pages/story', $html);
    }

    // ---- SSR page rendering (Task 11) ----

    public function test_published_page_renders_with_sections_and_seo(): void
    {
        $pageId = $this->api()->postJson($this->base().'/pages', ['title' => 'Campaign', 'slug' => 'campaign', 'seo' => ['title' => 'Big Sale']])->json('data.id');
        $this->api()->putJson($this->base()."/pages/{$pageId}/sections", ['sections' => [['type' => 'hero', 'settings' => ['headline' => 'Mega Sale']], ['type' => 'product-grid']]]);
        $this->api()->postJson($this->base()."/pages/{$pageId}/publish");

        $html = $this->get('http://nike.sellchase.com/pages/campaign')->assertOk()->getContent();
        $this->assertStringContainsString('data-section="hero"', $html);
        $this->assertStringContainsString('data-section="product-grid"', $html);
        $this->assertStringContainsString('Mega Sale', $html);
        $this->assertStringContainsString('<title>Big Sale — Nike</title>', $html);
        $this->assertStringContainsString('nike.sellchase.com/pages/campaign', $html); // canonical
    }

    public function test_draft_and_future_scheduled_pages_are_hidden(): void
    {
        $draft = $this->api()->postJson($this->base().'/pages', ['title' => 'D', 'slug' => 'draft-page'])->json('data.id');
        $this->get('http://nike.sellchase.com/pages/draft-page')->assertNotFound();

        $sched = $this->api()->postJson($this->base().'/pages', ['title' => 'S', 'slug' => 'sched'])->json('data.id');
        $this->api()->postJson($this->base()."/pages/{$sched}/schedule", ['publish_at' => now()->addDay()->toISOString()]);
        $this->get('http://nike.sellchase.com/pages/sched')->assertNotFound(); // future -> hidden
    }

    public function test_past_scheduled_page_is_visible(): void
    {
        $id = $this->api()->postJson($this->base().'/pages', ['title' => 'Past', 'slug' => 'past'])->json('data.id');
        $this->api()->putJson($this->base()."/pages/{$id}/sections", ['sections' => [['type' => 'hero']]]);
        $this->api()->postJson($this->base()."/pages/{$id}/schedule", ['publish_at' => now()->subMinute()->toISOString()]);

        $this->get('http://nike.sellchase.com/pages/past')->assertOk(); // publish_at is past -> publicly visible
    }
}

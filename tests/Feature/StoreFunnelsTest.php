<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreFunnel;
use App\Models\StorePage;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreFunnelsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $this->store = $this->makeStore('funnel');
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Canvas bag', 'slug' => 'canvas-bag', 'description' => 'A washable bag.', 'price' => 25, 'is_active' => true]);
        $this->asOwner($this->store);
    }

    private function makeStore(string $slug): Store
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => $slug, 'slug' => $slug, 'status' => 'active', 'currency' => 'USD', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar']]);
        StoreDomain::create(['store_id' => $store->id, 'host' => $slug.'.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($store);

        return $store;
    }

    private function asOwner(Store $store): void
    {
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($store->owner));
    }

    private function createFunnel(array $extra = [])
    {
        return $this->postJson('/api/v1/my-store/funnels', array_merge(['title' => 'Bag campaign', 'slug' => 'bag-campaign', 'locale' => 'en', 'product_id' => $this->product->id, 'template_key' => 'spotlight'], $extra));
    }

    public function test_create_persists_editable_draft_and_product_cta(): void
    {
        $funnel = $this->createFunnel()->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $page = StorePage::forStore($this->store)->with('sections')->findOrFail($funnel['page_id']);
        $this->assertSame('landing', $page->template);
        $this->assertCount(2, $page->sections);
        $this->assertSame('/products/canvas-bag', $page->sections[0]->settings['cta_url']);
        $this->getJson('/api/v1/my-store/funnels?search=Bag')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/my-store/funnels?search=Other')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/my-store/funnels?status=published')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_foreign_product_and_funnel_are_rejected(): void
    {
        $id = $this->createFunnel()->assertCreated()->json('data.id');
        $other = $this->makeStore('other');
        $this->asOwner($other);
        $this->createFunnel()->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->postJson('/api/v1/my-store/funnels/'.$id.'/duplicate')->assertNotFound();
        $this->getJson('/api/v1/my-store/funnels')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stores/'.$this->store->id.'/funnels')->assertForbidden();
    }

    public function test_duplicate_is_an_independent_draft_of_published_funnel(): void
    {
        $source = $this->createFunnel()->assertCreated()->json('data');
        $this->postJson('/api/v1/my-store/pages/'.$source['page_id'].'/publish')->assertOk();
        $copy = $this->postJson('/api/v1/my-store/funnels/'.$source['id'].'/duplicate')->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertNotSame($source['page_id'], $copy['page_id']);
        $this->assertNotSame($source['slug'], $copy['slug']);
        $this->putJson('/api/v1/my-store/pages/'.$copy['page_id'].'/sections', ['sections' => [['type' => 'hero-banner', 'settings' => ['heading' => 'New campaign']]]])->assertOk();
        $this->assertSame('published', StorePage::forStore($this->store)->findOrFail($source['page_id'])->status);
        $this->assertDatabaseHas('store_page_publications', ['store_page_id' => $source['page_id']]);
        $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        $this->getJson('/api/v1/my-store/pages/'.$source['page_id'])->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Canvas bag');
    }

    public function test_invalid_template_locale_and_slug_leave_no_partial_records(): void
    {
        $this->createFunnel(['template_key' => 'unknown', 'locale' => 'xx', 'slug' => '../bad'])->assertUnprocessable()->assertJsonValidationErrors(['template_key', 'locale', 'slug']);
        $this->assertDatabaseCount('store_funnels', 0);
        $this->assertDatabaseCount('store_pages', 0);
    }

    public function test_no_tenant_scope_returns_no_funnels(): void
    {
        $this->createFunnel()->assertCreated();
        app(CurrentStore::class)->set(null);
        $this->assertSame(0, StoreFunnel::query()->count());
    }

    public function test_public_funnel_uses_published_snapshot_and_unpublishes(): void
    {
        $funnel = $this->createFunnel()->assertCreated()->json('data');
        $url = 'http://funnel.sellchase.com/api/v1/storefront/pages/'.$funnel['slug'];
        $this->getJson($url)->assertNotFound();
        $this->postJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/publish')->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.cta_url', '/products/canvas-bag');
        $this->putJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/sections', ['sections' => [['type' => 'hero-banner', 'settings' => ['heading' => 'Draft only']]]])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Canvas bag');
        $this->postJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/unpublish')->assertOk();
        $this->getJson($url)->assertNotFound();
    }

    public function test_template_uses_selected_locale_and_product_image(): void
    {
        $this->product->image = 'https://example.com/bag.jpg';
        $this->product->setTranslations('name', ['en' => 'Canvas bag', 'ar' => 'حقيبة قماش']);
        $this->product->save();
        $funnel = $this->createFunnel(['locale' => 'ar'])->assertCreated()->json('data');
        $this->getJson('/api/v1/my-store/pages/'.$funnel['page_id'])->assertOk()
            ->assertJsonPath('data.sections.0.settings.heading', 'حقيبة قماش')
            ->assertJsonPath('data.sections.0.settings.image', 'https://example.com/bag.jpg');
    }
}

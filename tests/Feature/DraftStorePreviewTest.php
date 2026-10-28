<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;
use App\Models\StoreTheme;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Stores\StoreDomainResolver;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemePreviewToken;
use App\Services\Themes\ThemeRegistry;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DraftStorePreviewTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private StoreTheme $install;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create([
            'owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Private draft',
            'slug' => 'private-draft', 'status' => 'draft', 'currency' => 'KWD',
            'default_locale' => 'ar', 'supported_locales' => ['ar', 'en'],
        ]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'private-draft.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($this->store);
        $this->install = StoreTheme::where('store_id', $this->store->id)->firstOrFail();
        $this->install->update(['draft_settings' => ['primary_color' => '#5A267D']]);
        Product::withoutGlobalScopes()->create([
            'store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Private product',
            'slug' => 'private-product', 'price' => 58.55, 'is_active' => false, 'is_featured' => true,
        ]);
    }

    private function token(int $ttl = 1800): string
    {
        return app(ThemePreviewToken::class)->make($this->store->id, $this->install->id, $ttl);
    }

    private function url(string $path, ?string $token = null): string
    {
        return 'http://private-draft.sellchase.com'.$path.($token === null ? '' : (str_contains($path, '?') ? '&' : '?').'__preview='.urlencode($token));
    }

    public function test_owner_can_mint_a_capability_for_shell_bootstrap_layout_and_home_context_without_publishing(): void
    {
        $response = $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner))
            ->postJson('/api/v1/my-store/themes/preview', ['theme_id' => $this->install->theme_id])->assertOk();
        parse_str(parse_url($response->json('preview_url'), PHP_URL_QUERY), $query);
        $token = $query['__preview'];
        $this->withHeader('Authorization', '');

        $this->get($this->url('/', $token))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson($this->url('/api/v1/storefront?lang=en', $token))->assertOk()
            ->assertJsonPath('store.id', $this->store->id)->assertJsonPath('store.status', 'draft')
            ->assertJsonPath('preview.valid', true)->assertJsonPath('locale.current', 'en')
            ->assertJsonPath('theme.settings.primary_color', '#5A267D')->assertJsonCount(0, 'homepage.featured_products');
        foreach (['/api/v1/storefront/layout', '/api/v1/storefront/context'] as $path) {
            $this->getJson($this->url($path, $token))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $this->assertDatabaseHas('stores', ['id' => $this->store->id, 'status' => 'draft']);
        $this->assertDatabaseHas('products', ['slug' => 'private-product', 'is_active' => false]);
        $this->assertDatabaseCount('store_orders', 0);
        $sharedPages = array_filter(array_keys(Cache::getStore()->all()), fn (string $key) => str_starts_with($key, 'sflayout:') || str_starts_with($key, 'sfpage:html:'));
        $this->assertSame([], $sharedPages, 'Private theme previews must not populate shared page/layout caches.');
    }

    public function test_unsigned_forged_expired_and_cross_store_capabilities_cannot_resolve_a_draft(): void
    {
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-draft', 'status' => 'draft', 'currency' => 'USD']);
        $wrongStore = app(ThemePreviewToken::class)->make($other->id, $this->install->id);
        foreach ([null, 'forged', $this->token(-60), $wrongStore] as $token) {
            foreach (['/', '/api/v1/storefront', '/api/v1/storefront/layout', '/api/v1/storefront/context'] as $path) {
                $this->getJson($this->url($path, $token))->assertNotFound();
            }
        }
        $this->getJson($this->url('/api/v1/storefront?preview=1'))->assertNotFound();
        $this->getJson('http://other-draft.sellchase.com/api/v1/storefront?__preview='.$this->token())->assertNotFound();
        $this->getJson('http://ghost.sellchase.com/api/v1/storefront?__preview='.$this->token())->assertNotFound();
    }

    public function test_signed_preview_does_not_open_catalog_checkout_auth_uploads_or_other_commerce_routes(): void
    {
        $token = $this->token();
        foreach (['/products', '/products/private-product', '/checkout', '/api/v1/storefront/home', '/api/v1/storefront/products', '/api/v1/storefront/products/private-product', '/api/v1/storefront/collections', '/api/v1/storefront/categories', '/api/v1/storefront/payment-methods', '/api/v1/storefront/checkout/fields', '/api/v1/storefront/context?template=product&slug=private-product'] as $path) {
            $this->getJson($this->url($path, $token))->assertNotFound();
        }
        foreach (['/api/v1/storefront/checkout', '/api/v1/storefront/checkout/payment/retry', '/api/v1/storefront/auth/login', '/api/v1/storefront/checkout/receipt/upload', '/api/v1/storefront/cart/catalog'] as $path) {
            $this->postJson($this->url($path, $token), [])->assertNotFound();
        }
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_page_capability_only_exposes_its_existing_store_owned_page(): void
    {
        $page = StorePage::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'title' => 'Draft copy', 'slug' => 'draft-copy', 'template' => 'page', 'status' => 'draft', 'locale' => 'en']);
        $token = app(ThemePreviewToken::class)->makePage($this->store->id, $page->id);
        $this->getJson($this->url('/api/v1/storefront/pages/draft-copy', $token))->assertOk()->assertJsonPath('data.id', $page->id)->assertHeader('Cache-Control', 'no-store, private');
        $this->get($this->url('/pages/draft-copy', $token))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson($this->url('/api/v1/storefront/pages/another-page', $token))->assertNotFound();
        $this->getJson($this->url('/api/v1/storefront/pages/draft-copy'))->assertNotFound();
        $missing = app(ThemePreviewToken::class)->makePage($this->store->id, $page->id + 100);
        $this->getJson($this->url('/api/v1/storefront', $missing))->assertNotFound();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-page-store', 'status' => 'draft', 'currency' => 'USD']);
        $foreignPage = StorePage::withoutGlobalScopes()->create(['store_id' => $other->id, 'title' => 'Foreign', 'slug' => 'foreign-page', 'template' => 'page', 'status' => 'draft', 'locale' => 'en']);
        $crossPage = app(ThemePreviewToken::class)->makePage($this->store->id, $foreignPage->id);
        $this->getJson($this->url('/api/v1/storefront/pages/foreign-page', $crossPage))->assertNotFound();
    }

    public function test_preview_does_not_pollute_host_cache_and_revoked_or_non_draft_stores_stay_hidden(): void
    {
        $resolver = app(StoreDomainResolver::class);
        $host = 'private-draft.sellchase.com';
        $this->assertNull($resolver->resolve($host));
        $token = $this->token();
        $this->getJson($this->url('/api/v1/storefront', $token))->assertOk();
        $this->assertNull($resolver->resolve($host));
        $this->getJson($this->url('/api/v1/storefront'))->assertNotFound();
        $this->assertNull(app(CurrentStore::class)->id());
        $this->store->update(['status' => 'suspended']);
        $this->getJson($this->url('/api/v1/storefront', $token))->assertNotFound();
        $this->store->update(['status' => 'draft']);
        $this->install->delete();
        $this->getJson($this->url('/api/v1/storefront', $token))->assertNotFound();
    }

    public function test_signed_capability_serves_the_real_spa_shell_path_but_unsigned_visitors_cannot_load_it(): void
    {
        $shell = tempnam(sys_get_temp_dir(), 'draft-preview-shell-');
        file_put_contents($shell, '<html><head><title>Fixture shell</title></head><body><div id="storefront-root"></div></body></html>');
        config()->set('sellchase.storefront.spa_shell', $shell);
        try {
            $this->get($this->url('/', $this->token()))->assertOk()->assertSee('id="storefront-root"', false)->assertHeader('Cache-Control', 'no-store, private');
            $this->get($this->url('/'))->assertNotFound();
        } finally {
            unlink($shell);
        }
    }

    public function test_custom_host_must_be_verified_and_capability_cannot_select_a_reserved_host(): void
    {
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'private-merchant.example', 'type' => 'custom', 'is_primary' => false, 'status' => StoreDomain::STATUS_VERIFIED]);
        $token = $this->token();
        $this->getJson('http://private-merchant.example/api/v1/storefront?__preview='.$token)->assertOk()->assertJsonPath('store.id', $this->store->id);
        StoreDomain::where('host', 'private-merchant.example')->firstOrFail()->update(['status' => StoreDomain::STATUS_PENDING]);
        $this->getJson('http://private-merchant.example/api/v1/storefront?__preview='.$token)->assertNotFound();
        $this->getJson('http://admin.sellchase.com/api/v1/storefront?__preview='.$token)->assertNotFound();
    }
}

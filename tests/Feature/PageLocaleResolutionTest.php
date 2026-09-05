<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** E5: page `locale` is validated/defaulted per store; the public route picks the sibling for the request locale. */
class PageLocaleResolutionTest extends TestCase
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
        $this->store = Store::create([
            'owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active', 'default_locale' => 'ar', 'supported_locales' => ['ar', 'en'],
        ]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($this->store);
    }

    private function api(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }

    public function test_locale_defaults_to_the_store_default_and_is_validated(): void
    {
        $base = "/api/v1/stores/{$this->store->id}/pages";
        $this->api()->postJson($base, ['title' => 'About'])->assertCreated()->assertJsonPath('data.locale', 'ar');
        $this->api()->postJson($base, ['title' => 'About', 'locale' => 'en'])->assertCreated()->assertJsonPath('data.locale', 'en');
        $this->api()->postJson($base, ['title' => 'About', 'locale' => 'fr'])->assertStatus(422)->assertJsonValidationErrors(['locale']);
    }

    public function test_public_page_picks_the_sibling_for_the_request_locale_with_fallback(): void
    {
        $base = "/api/v1/stores/{$this->store->id}/pages";
        $ar = $this->api()->postJson($base, ['title' => 'من نحن', 'slug' => 'about', 'locale' => 'ar'])->json('data.id');
        $en = $this->api()->postJson($base, ['title' => 'About us', 'slug' => 'about', 'locale' => 'en'])->json('data.id');
        $this->assertSame('about', StorePage::query()->find($en)->slug); // same slug per locale
        $this->api()->postJson("{$base}/{$ar}/publish")->assertOk();
        $this->api()->postJson("{$base}/{$en}/publish")->assertOk();

        $this->get('http://nike.sellchase.com/pages/about?lang=en')->assertOk()->assertSee('About us')->assertHeader('Content-Language', 'en');
        $this->get('http://nike.sellchase.com/pages/about?lang=ar')->assertOk()->assertSee('من نحن');
        // No usable hint (the test client always sends Accept-Language: en-us, so force an unsupported one) → store default.
        $this->get('http://nike.sellchase.com/pages/about', ['Accept-Language' => 'fr'])->assertOk()->assertSee('من نحن');

        // Unpublish the English sibling: English requests fall back to the Arabic page.
        $this->api()->postJson("{$base}/{$en}/unpublish")->assertOk();
        $this->get('http://nike.sellchase.com/pages/about?lang=en')->assertOk()->assertSee('من نحن');

        // No published sibling at all → 404.
        $this->api()->postJson("{$base}/{$ar}/unpublish")->assertOk();
        $this->get('http://nike.sellchase.com/pages/about?lang=en')->assertNotFound();
    }
}

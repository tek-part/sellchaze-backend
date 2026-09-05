<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E0: `?lang` / `?locale` / Accept-Language → store default, constrained to supported locales,
 * exposed as the `locale` block + Content-Language / Vary headers.
 */
class ResolveStorefrontLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(string $slug, string $default = 'en', array $supported = ['en', 'ar']): Store
    {
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant',
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
            'default_locale' => $default, 'supported_locales' => $supported,
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);

        return $store;
    }

    public function test_query_lang_wins_and_sets_headers(): void
    {
        $this->makeStore('nike');

        $res = $this->getJson('http://nike.sellchase.com/api/v1/storefront?lang=ar')->assertOk();
        $res->assertJsonPath('locale.current', 'ar')
            ->assertJsonPath('locale.fallback', 'en')
            ->assertJsonPath('locale.supported', ['en', 'ar'])
            ->assertJsonPath('locale.dir', 'rtl')
            ->assertHeader('Content-Language', 'ar');
        $this->assertStringContainsString('Accept-Language', (string) $res->headers->get('Vary'));
        $this->assertSame('ar', app()->getLocale());
    }

    public function test_locale_query_and_accept_language_are_honoured_in_order(): void
    {
        $this->makeStore('nike');

        $this->getJson('http://nike.sellchase.com/api/v1/storefront?locale=ar')->assertJsonPath('locale.current', 'ar');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront', ['Accept-Language' => 'ar-EG,ar;q=0.9,en;q=0.5'])
            ->assertJsonPath('locale.current', 'ar')->assertHeader('Content-Language', 'ar');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront?lang=en', ['Accept-Language' => 'ar'])
            ->assertJsonPath('locale.current', 'en');
    }

    public function test_unsupported_locales_fall_back_to_the_store_default(): void
    {
        $this->makeStore('adidas', 'ar', ['ar']);

        $this->getJson('http://adidas.sellchase.com/api/v1/storefront?lang=en')
            ->assertOk()
            ->assertJsonPath('locale.current', 'ar')
            ->assertJsonPath('locale.supported', ['ar'])
            ->assertHeader('Content-Language', 'ar');
        $this->getJson('http://adidas.sellchase.com/api/v1/storefront?lang=xx', ['Accept-Language' => 'fr'])
            ->assertJsonPath('locale.current', 'ar');
    }

    public function test_default_locale_applies_without_hints(): void
    {
        $this->makeStore('puma', 'ar', ['ar', 'en']);

        // Symfony's test request always carries `Accept-Language: en-us`; an unsupported preference must not override the default.
        $this->getJson('http://puma.sellchase.com/api/v1/storefront', ['Accept-Language' => 'fr-FR,fr;q=0.8'])
            ->assertJsonPath('locale.current', 'ar')
            ->assertJsonPath('locale.dir', 'rtl')
            ->assertJsonPath('store.default_locale', 'ar');
    }

    public function test_server_rendered_pages_carry_content_language(): void
    {
        $this->makeStore('nike');

        $this->get('http://nike.sellchase.com/?lang=ar')->assertOk()->assertHeader('Content-Language', 'ar');
        $this->get('http://nike.sellchase.com/')->assertOk()->assertHeader('Content-Language', 'en');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Salla-style customizer backend: the singleton `home` template page (seeded from the
 * active theme manifest), rich section schemas (list / {value,label} options /
 * translatable text), the public layout API and the `themes:register` command.
 */
class HomeLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/themes/rich-sections.json';

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
            'currency' => 'USD', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar'],
        ]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($this->store);
    }

    private function api(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }

    private function base(): string
    {
        return "/api/v1/stores/{$this->store->id}/pages";
    }

    private function storefront(): string
    {
        return 'http://nike.sellchase.com/api/v1/storefront';
    }

    /** Register the rich fixture theme and make it the store's active theme. */
    private function activateRichTheme(): void
    {
        $theme = app(ThemeRegistry::class)->registerFromFile(base_path(self::FIXTURE));
        $version = app(ThemeRegistry::class)->resolveThemeVersion($theme);
        $service = app(StoreThemeService::class);
        $service->activate($this->store, $service->install($this->store, $theme, $version));
    }

    public function test_ensure_home_creates_a_draft_seeded_from_the_manifest_and_is_idempotent(): void
    {
        $first = $this->api()->getJson($this->base().'/template/home')->assertCreated()
            ->assertJsonPath('data.template', 'home')
            ->assertJsonPath('data.slug', 'home')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.has_unpublished_changes', true)
            ->assertJsonCount(3, 'data.sections');

        // Seeded from templates.home of the default theme: defaults + template-level settings kept.
        $first->assertJsonPath('data.sections.0.type', 'hero')
            ->assertJsonPath('data.sections.0.settings.show_tagline', true)
            ->assertJsonPath('data.sections.2.type', 'product-grid')
            ->assertJsonPath('data.sections.2.settings.limit', 8)
            ->assertJsonPath('data.sections.2.settings.featured_only', true);

        $second = $this->api()->getJson($this->base().'/template/home')->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, StorePage::query()->where('template', 'home')->count());

        // One per (store, locale): the Arabic home is a separate row, and an unsupported locale is rejected.
        $ar = $this->api()->getJson($this->base().'/template/home?locale=ar')->assertCreated()->assertJsonPath('data.locale', 'ar');
        $this->assertNotSame($first->json('data.id'), $ar->json('data.id'));
        $this->api()->getJson($this->base().'/template/home?locale=fr')->assertStatus(422);

        // POST pages with template=home returns the existing singleton instead of a duplicate.
        $this->api()->postJson($this->base(), ['title' => 'Another', 'template' => 'home'])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(2, StorePage::query()->where('template', 'home')->count());
    }

    public function test_pages_index_excludes_the_home_row_unless_filtered(): void
    {
        $homeId = $this->api()->getJson($this->base().'/template/home')->json('data.id');
        $this->api()->postJson($this->base(), ['title' => 'About', 'slug' => 'about'])->assertCreated();
        $this->api()->postJson($this->base(), ['title' => 'Promo', 'slug' => 'promo', 'template' => 'landing'])->assertCreated();

        $this->api()->getJson($this->base())->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonMissing(['template' => 'home']);
        $this->api()->getJson($this->base().'?template=page,landing')->assertOk()->assertJsonPath('meta.total', 2);
        $this->api()->getJson($this->base().'?template=landing')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'promo');
        $this->api()->getJson($this->base().'?template=home')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $homeId);

        // A custom page cannot be converted into the home template; the home page keeps its slug/template.
        $aboutId = StorePage::query()->where('slug', 'about')->value('id');
        $this->api()->putJson($this->base()."/{$aboutId}", ['template' => 'home'])->assertStatus(422)->assertJsonValidationErrors(['template']);
        $this->api()->putJson($this->base()."/{$homeId}", ['title' => 'Front', 'slug' => 'front', 'template' => 'page'])->assertOk()
            ->assertJsonPath('data.title', 'Front')->assertJsonPath('data.slug', 'home')->assertJsonPath('data.template', 'home');
    }

    public function test_schema_endpoint_passes_rich_section_metadata_through(): void
    {
        $this->activateRichTheme();

        $this->api()->getJson($this->base().'/schema')->assertOk()
            ->assertJsonPath('theme.key', 'rich-test')
            ->assertJsonPath('sections_schema.hero.category', 'hero')
            ->assertJsonPath('sections_schema.hero.icon', 'HiOutlinePhoto')
            ->assertJsonPath('sections_schema.hero.description', 'Full-width opening banner')
            ->assertJsonPath('sections_schema.hero.settings.1.options.0.value', 'start')
            ->assertJsonPath('sections_schema.hero.settings.0.translatable', true)
            ->assertJsonPath('sections_schema.feature-list.settings.0.item.0.id', 'title')
            ->assertJsonPath('settings_schema.0.id', 'brand')
            ->assertJsonPath('settings_schema.0.fields.2.options.1.label', 'Boxed');
    }

    public function test_sections_put_round_trips_list_values_and_coerces_rich_field_shapes(): void
    {
        $this->activateRichTheme();
        $id = $this->api()->getJson($this->base().'/template/home')->assertCreated()
            ->assertJsonPath('data.sections.0.settings.headline.ar', 'رئيسية')
            ->json('data.id');

        $items = [
            ['title' => ['en' => 'Fast', 'ar' => 'سريع'], 'icon' => 'bolt'],
            ['title' => 'Safe', 'icon' => 'nope'],   // unknown option -> item default
            ['title' => 'Dropped by max=2'],
        ];
        $response = $this->api()->putJson($this->base()."/{$id}/sections", ['sections' => [
            ['type' => 'feature-list', 'settings' => ['items' => $items]],
            ['type' => 'hero', 'settings' => ['headline' => ['en' => 'Hi', 'ar' => 'أهلا'], 'align' => 'start', 'show_cta' => 'false']],
            ['type' => 'hero', 'settings' => ['headline' => 'Plain string', 'align' => 'diagonal']],
            ['type' => 'not-in-theme', 'settings' => []],
        ]])->assertOk();

        $response->assertJsonCount(3, 'data.sections')
            ->assertJsonPath('data.sections.0.settings.items.0.title.ar', 'سريع')
            ->assertJsonPath('data.sections.0.settings.items.0.icon', 'bolt')
            ->assertJsonPath('data.sections.0.settings.items.1.icon', 'star')
            ->assertJsonCount(2, 'data.sections.0.settings.items')
            ->assertJsonPath('data.sections.1.settings.headline.en', 'Hi')
            ->assertJsonPath('data.sections.1.settings.align', 'start')
            ->assertJsonPath('data.sections.1.settings.show_cta', false)
            ->assertJsonPath('data.sections.2.settings.headline', 'Plain string')
            ->assertJsonPath('data.sections.2.settings.align', 'center');

        $this->api()->getJson($this->base()."/{$id}")->assertOk()->assertJsonPath('data.sections.0.settings.items.0.title.en', 'Fast');

        // Hard cap: 61 sections are rejected before touching the layout.
        $tooMany = array_fill(0, 61, ['type' => 'hero']);
        $this->api()->putJson($this->base()."/{$id}/sections", ['sections' => $tooMany])->assertStatus(422)->assertJsonValidationErrors(['sections']);
    }

    public function test_public_layout_serves_the_theme_template_until_the_home_page_is_published(): void
    {
        $this->getJson($this->storefront().'/layout?template=home')->assertOk()
            ->assertJsonPath('data.template', 'home')
            ->assertJsonPath('data.source', 'theme')
            ->assertJsonPath('data.page_id', null)
            ->assertJsonCount(3, 'data.sections')
            ->assertJsonPath('data.sections.0.type', 'hero')
            ->assertJsonPath('data.sections.2.settings.limit', 8);

        // A draft (unpublished) home page changes nothing publicly.
        $id = $this->api()->getJson($this->base().'/template/home')->json('data.id');
        $this->api()->putJson($this->base()."/{$id}/sections", ['sections' => [['type' => 'hero', 'settings' => ['headline' => 'Draft only']]]])->assertOk();
        $this->getJson($this->storefront().'/layout?template=home')->assertOk()->assertJsonPath('data.source', 'theme')->assertJsonCount(3, 'data.sections');

        $this->getJson($this->storefront().'/layout?template=product')->assertStatus(422);
    }

    public function test_public_layout_serves_the_published_home_snapshot_with_hidden_sections_omitted(): void
    {
        $id = $this->api()->getJson($this->base().'/template/home')->json('data.id');
        $this->api()->putJson($this->base()."/{$id}/sections", ['sections' => [
            ['type' => 'hero', 'settings' => ['headline' => 'Published hero']],
            ['type' => 'category-list', 'is_visible' => false],
            ['type' => 'product-grid', 'settings' => ['limit' => 4]],
        ]])->assertOk();
        $this->api()->postJson($this->base()."/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        $layout = $this->getJson($this->storefront().'/layout?template=home')->assertOk()
            ->assertJsonPath('data.source', 'store')
            ->assertJsonPath('data.page_id', $id)
            ->assertJsonCount(2, 'data.sections')
            ->assertJsonPath('data.sections.0.id', 'published-0')
            ->assertJsonPath('data.sections.0.settings.headline', 'Published hero')
            ->assertJsonPath('data.sections.1.id', 'published-1')
            ->assertJsonPath('data.sections.1.type', 'product-grid')
            ->assertJsonPath('data.sections.1.settings.limit', 4);
        $this->assertNotContains('category-list', array_column($layout->json('data.sections'), 'type'));

        // Draft edits after publishing stay private until the next publish.
        $this->api()->putJson($this->base()."/{$id}/sections", ['sections' => [['type' => 'hero', 'settings' => ['headline' => 'Newer draft']]]])->assertOk();
        $this->getJson($this->storefront().'/layout?template=home')->assertOk()->assertJsonCount(2, 'data.sections')->assertJsonPath('data.sections.0.settings.headline', 'Published hero');
        $this->api()->postJson($this->base()."/{$id}/publish")->assertOk();
        $this->getJson($this->storefront().'/layout?template=home')->assertOk()->assertJsonCount(1, 'data.sections')->assertJsonPath('data.sections.0.settings.headline', 'Newer draft');

        // Locale fallback: an Arabic request with no Arabic home falls back to the default-locale page.
        $this->getJson($this->storefront().'/layout?template=home&lang=ar')->assertOk()->assertJsonPath('data.source', 'store')->assertJsonPath('data.page_id', $id);

        // The Blade/SSR home render agrees with the API (published store sections, not the manifest).
        $this->get('http://nike.sellchase.com/')->assertOk()->assertSee('Newer draft');

        // Unpublish -> back to the manifest template; the home page is never served as a custom page.
        $this->api()->postJson($this->base()."/{$id}/unpublish")->assertOk();
        $this->getJson($this->storefront().'/layout?template=home')->assertOk()->assertJsonPath('data.source', 'theme')->assertJsonCount(3, 'data.sections');
        $this->getJson($this->storefront().'/pages/home')->assertNotFound();
        $this->get('http://nike.sellchase.com/pages/home')->assertNotFound();
    }

    public function test_public_custom_page_endpoint_serves_published_pages_only(): void
    {
        $id = $this->api()->postJson($this->base(), ['title' => 'About us', 'slug' => 'about', 'seo' => ['description' => 'Who we are']])->assertCreated()->json('data.id');
        $this->api()->putJson($this->base()."/{$id}/sections", ['sections' => [
            ['type' => 'rich-text', 'settings' => ['content' => 'Hello']],
            ['type' => 'hero', 'is_visible' => false],
        ]])->assertOk();

        $this->getJson($this->storefront().'/pages/about')->assertNotFound(); // draft
        $this->api()->postJson($this->base()."/{$id}/publish")->assertOk();

        $this->getJson($this->storefront().'/pages/about')->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.title', 'About us')
            ->assertJsonPath('data.slug', 'about')
            ->assertJsonPath('data.template', 'page')
            ->assertJsonPath('data.seo.description', 'Who we are')
            ->assertJsonCount(1, 'data.sections')
            ->assertJsonPath('data.sections.0.id', 'published-0')
            ->assertJsonPath('data.sections.0.type', 'rich-text')
            ->assertJsonPath('data.sections.0.settings.content', 'Hello');

        $this->getJson($this->storefront().'/pages/unknown')->assertNotFound()->assertJsonPath('message', 'Page not found.');

        $this->api()->postJson($this->base()."/{$id}/unpublish")->assertOk();
        $this->getJson($this->storefront().'/pages/about')->assertNotFound();
    }

    public function test_themes_register_command_registers_a_rich_manifest(): void
    {
        $this->artisan('themes:register', ['--path' => [self::FIXTURE], '--only' => 'rich-test'])
            ->expectsOutputToContain('rich-test')
            ->assertExitCode(0);

        $theme = Theme::query()->where('key', 'rich-test')->firstOrFail();
        $version = ThemeVersion::query()->find($theme->latest_version_id);
        $this->assertSame('1.0.0', $version->version);
        $this->assertSame('hero', $version->sections_schema['hero']['category']);
        $this->assertSame('HiOutlineSparkles', $version->sections_schema['feature-list']['icon']);
        $this->assertSame('start', $version->sections_schema['hero']['settings'][1]['options'][0]['value']);
        $this->assertSame(5, count($version->sections_schema));

        // Discovery covers every shipped manifest; `--only` narrows it; both are idempotent.
        $this->artisan('themes:register', ['--only' => 'default'])->assertExitCode(0);
        $this->assertSame(2, Theme::count());
        $this->artisan('themes:register')->assertExitCode(0);
        $this->assertGreaterThan(2, Theme::count());
        $this->assertContains(resource_path('themes/storefront/luxury-fashion.json'), ThemeRegistry::manifestPaths());

        // Malformed options are rejected with a readable error.
        $errors = app(ThemeRegistry::class)->validate([
            'key' => 'bad', 'name' => 'Bad', 'version' => '1.0.0', 'settings_schema' => [],
            'sections_schema' => ['hero' => ['settings' => [['id' => 'x', 'type' => 'select', 'options' => [['label' => 'no value']]]]]],
            'templates' => ['home' => ['sections' => []], 'product' => ['sections' => []], 'category' => ['sections' => []]],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("field 'x' options", $errors[0]);
    }
}

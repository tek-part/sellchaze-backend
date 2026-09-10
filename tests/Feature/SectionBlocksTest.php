<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\SectionRegistry;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract §7 (variants, nested blocks, shared section style): manifest validation,
 * write-side sanitization of `blocks` / `__style` / the variant field, template
 * seeding with block defaults, and the public layout + Blade fallback carrying the
 * published composition through.
 */
class SectionBlocksTest extends TestCase
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
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/storefront/naseem.json'));

        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create([
            'owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar'],
        ]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($this->store);

        $theme = app(ThemeRegistry::class)->registerFromFile(base_path(self::FIXTURE));
        $version = app(ThemeRegistry::class)->resolveThemeVersion($theme);
        $service = app(StoreThemeService::class);
        $service->activate($this->store, $service->install($this->store, $theme, $version));
    }

    private function api(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }

    private function base(): string
    {
        return "/api/v1/stores/{$this->store->id}/pages";
    }

    private function manifest(array $testimonials): array
    {
        return [
            'key' => 'blocks', 'name' => 'Blocks', 'version' => '1.0.0', 'settings_schema' => [],
            'sections_schema' => [
                'testimonials' => ['label' => 'T', 'settings' => [['id' => 'layout', 'type' => 'select', 'options' => ['grid']]], ...$testimonials],
                'product-grid' => ['label' => 'G', 'settings' => []], 'category-header' => ['label' => 'C', 'settings' => []], 'product-details' => ['label' => 'D', 'settings' => []],
            ],
            'templates' => [
                'home' => ['sections' => [['type' => 'testimonials']]],
                'product' => ['sections' => [['type' => 'product-details']]],
                'category' => ['sections' => [['type' => 'category-header'], ['type' => 'product-grid']]],
            ],
        ];
    }

    public function test_manifest_validation_covers_variants_blocks_and_style(): void
    {
        $registry = app(ThemeRegistry::class);
        $fixture = json_decode((string) file_get_contents(base_path(self::FIXTURE)), true);
        $this->assertSame([], $registry->validate($fixture));

        $good = [
            'variants' => ['field' => 'layout', 'options' => [['value' => 'grid', 'label' => 'Grid', 'icon' => 'X']]],
            'blocks' => ['min' => 0, 'max' => 4, 'types' => [['type' => 'quote', 'label' => 'Quote', 'limit' => 2, 'settings' => [['id' => 'text', 'type' => 'text']]]]],
            'style' => true,
        ];
        $this->assertSame([], $registry->validate($this->manifest($good)));

        $cases = [
            "block 'quote' has a field without an id" => ['blocks' => ['types' => [['type' => 'quote', 'label' => 'Q', 'settings' => [['type' => 'text']]]]]],
            "block 'quote' field 'kind' options" => ['blocks' => ['types' => [['type' => 'quote', 'label' => 'Q', 'settings' => [['id' => 'kind', 'type' => 'select', 'options' => [['label' => 'no value']]]]]]]],
            "block 'quote' list 'tags' has a field without an id" => ['blocks' => ['types' => [['type' => 'quote', 'label' => 'Q', 'settings' => [['id' => 'tags', 'type' => 'list', 'item' => [['type' => 'text']]]]]]]],
            "block 'quote' settings must be a list of fields" => ['blocks' => ['types' => [['type' => 'quote', 'label' => 'Q']]]],
            "block 'quote' must have a string label" => ['blocks' => ['types' => [['type' => 'quote', 'settings' => []]]]],
            "block 'quote' is declared more than once" => ['blocks' => ['types' => [['type' => 'quote', 'label' => 'Q', 'settings' => []], ['type' => 'quote', 'label' => 'Q2', 'settings' => []]]]],
            "block 'quote' limit must be a non-negative integer" => ['blocks' => ['types' => [['type' => 'quote', 'label' => 'Q', 'limit' => -1, 'settings' => []]]]],
            'blocks.types[0] must have a string type' => ['blocks' => ['types' => [['label' => 'Q', 'settings' => []]]]],
            'blocks.types must be a list of block types' => ['blocks' => ['types' => ['quote' => []]]],
            'blocks.max must be a non-negative integer' => ['blocks' => ['max' => 'many', 'types' => []]],
            'blocks.min must not exceed blocks.max' => ['blocks' => ['min' => 3, 'max' => 1, 'types' => []]],
            'blocks must be an object' => ['blocks' => 'nope'],
            'variants.field must be a non-empty string' => ['variants' => ['options' => [['value' => 'a', 'label' => 'A']]]],
            'variants.options must be a non-empty list' => ['variants' => ['field' => 'layout', 'options' => []]],
            'variants.options[0] must be a {value,label} object' => ['variants' => ['field' => 'layout', 'options' => ['grid']]],
            'variants.options[0] icon must be a string' => ['variants' => ['field' => 'layout', 'options' => [['value' => 'a', 'label' => 'A', 'icon' => 1]]]],
            'style must be a boolean' => ['style' => 'yes'],
        ];
        foreach ($cases as $expected => $definition) {
            $errors = $registry->validate($this->manifest($definition));
            $this->assertNotEmpty($errors, $expected);
            $this->assertStringContainsString($expected, implode("\n", $errors));
        }
    }

    public function test_template_seeding_keeps_manifest_blocks_and_fills_block_defaults(): void
    {
        $page = $this->api()->getJson($this->base().'/template/home')->assertCreated()
            ->assertJsonPath('data.sections.3.type', 'testimonials')
            ->assertJsonPath('data.sections.3.settings.layout', 'carousel')
            ->assertJsonPath('data.sections.3.settings.heading.en', 'What customers say')
            ->assertJsonCount(2, 'data.sections.3.settings.blocks')
            ->assertJsonPath('data.sections.3.settings.blocks.0.id', 'b_seed_1')
            ->assertJsonPath('data.sections.3.settings.blocks.0.type', 'quote')
            ->assertJsonPath('data.sections.3.settings.blocks.0.hidden', false)
            ->assertJsonPath('data.sections.3.settings.blocks.0.settings.author', 'Sara')
            ->assertJsonPath('data.sections.3.settings.blocks.0.settings.text.ar', 'متجر رائع') // block-type default
            ->assertJsonPath('data.sections.3.settings.blocks.0.settings.rating', 5)
            ->assertJsonPath('data.sections.3.settings.blocks.1.type', 'cta')
            ->assertJsonPath('data.sections.3.settings.blocks.1.settings.style', 'ghost')
            ->assertJsonPath('data.sections.3.settings.blocks.1.settings.label', 'Shop now');
        $this->assertStringStartsWith('b_', $page->json('data.sections.3.settings.blocks.1.id'));

        // Sections without a `blocks` schema never get the key, even from a template.
        $this->assertArrayNotHasKey('blocks', $page->json('data.sections.2.settings'));

        // Direct registry check: the manifest-template override wins over the type default, defaults fill the rest.
        $schema = json_decode((string) file_get_contents(base_path(self::FIXTURE)), true)['sections_schema'];
        $resolved = app(SectionRegistry::class)->resolveSections($schema, [['type' => 'testimonials', 'settings' => ['blocks' => [['type' => 'quote', 'settings' => ['rating' => 3]]]]]]);
        $this->assertSame(3, $resolved[0]['settings']['blocks'][0]['settings']['rating']);
        $this->assertSame('', $resolved[0]['settings']['blocks'][0]['settings']['author']);
        $this->assertSame([], $resolved[0]['settings']['blocks'][0]['settings']['tags']);
        $this->assertSame('grid', $resolved[0]['settings']['layout']);
    }

    public function test_sections_put_sanitizes_blocks_variants_and_style(): void
    {
        $id = $this->api()->getJson($this->base().'/template/home')->json('data.id');

        $blocks = [
            ['id' => 'b_keep', 'type' => 'quote', 'hidden' => 'yes', 'settings' => ['text' => ['en' => 'Nice', 'ar' => 'جميل'], 'rating' => '4', 'author' => 'Ali', 'extra' => 'kept', 'tags' => [['label' => 'a'], ['label' => 'b'], ['label' => 'dropped by max']]]],
            ['type' => 'quote', 'settings' => ['rating' => 'not-a-number'], 'note' => 'block-level extra key passes through'],
            ['id' => 'b_bad', 'type' => 'unknown-block', 'settings' => ['x' => 1]],
            ['id' => 'b_cta', 'type' => 'cta', 'settings' => ['style' => 'ghost']],
            ['id' => 'b_cta_2', 'type' => 'cta', 'settings' => ['style' => 'primary']],   // over cta limit=1
            ['id' => 'b_over_max', 'type' => 'quote', 'settings' => []],                     // over blocks.max=3
            'not-an-object',
        ];
        $style = [
            'padding_top' => 400, 'padding_bottom' => -5, 'background' => 'custom', 'background_color' => '#ff0000',
            'background_image' => 'https://cdn.example.com/bg.png?w=1', 'container' => 'wide', 'text_align' => 'center',
            'hide_mobile' => 'true', 'hide_desktop' => 0, 'anchor' => 'Our Customers!', 'css_class' => 'hero  big</script>',
            'evil' => 'javascript:', 'padding_inline' => 10,
        ];

        $response = $this->api()->putJson($this->base()."/{$id}/sections", ['sections' => [
            ['type' => 'testimonials', 'settings' => ['layout' => 'diagonal', 'blocks' => $blocks, '__style' => $style, '__responsive' => ['padding_top' => ['mobile' => 16]], 'custom' => 'kept']],
            ['type' => 'product-grid', 'settings' => ['limit' => 6, 'blocks' => [['type' => 'quote']], '__style' => ['padding_top' => 8, 'background_image' => 'url("x")', 'background_color' => 'red;}body{']]],
            ['type' => 'testimonials', 'settings' => ['layout' => 'carousel', 'blocks' => 'nope', '__style' => 'nope']],
            ['type' => 'testimonials', 'settings' => ['__style' => ['bogus' => 1]]],
        ]])->assertOk();

        $response->assertJsonCount(4, 'data.sections')
            ->assertJsonPath('data.sections.0.settings.layout', 'grid')                       // variant reset to the field default
            ->assertJsonPath('data.sections.0.settings.custom', 'kept')
            ->assertJsonPath('data.sections.0.settings.__responsive.padding_top.mobile', 16)
            ->assertJsonCount(3, 'data.sections.0.settings.blocks')
            ->assertJsonPath('data.sections.0.settings.blocks.0.id', 'b_keep')
            ->assertJsonPath('data.sections.0.settings.blocks.0.hidden', true)
            ->assertJsonPath('data.sections.0.settings.blocks.0.settings.text.ar', 'جميل')
            ->assertJsonPath('data.sections.0.settings.blocks.0.settings.rating', 4)
            ->assertJsonPath('data.sections.0.settings.blocks.0.settings.extra', 'kept')
            ->assertJsonCount(2, 'data.sections.0.settings.blocks.0.settings.tags')
            ->assertJsonPath('data.sections.0.settings.blocks.1.type', 'quote')
            ->assertJsonPath('data.sections.0.settings.blocks.1.hidden', false)
            ->assertJsonPath('data.sections.0.settings.blocks.1.settings.rating', 5)       // bad number -> field default
            ->assertJsonPath('data.sections.0.settings.blocks.1.note', 'block-level extra key passes through')
            ->assertJsonPath('data.sections.0.settings.blocks.2.id', 'b_cta')
            ->assertJsonPath('data.sections.0.settings.blocks.2.settings.style', 'ghost')
            ->assertJsonPath('data.sections.0.settings.__style', [
                'padding_top' => 200, 'padding_bottom' => 0, 'background' => 'custom', 'background_color' => '#ff0000',
                'background_image' => 'https://cdn.example.com/bg.png?w=1', 'text_align' => 'center',
                'hide_mobile' => true, 'hide_desktop' => false, 'anchor' => 'our-customers', 'css_class' => 'hero bigscript',
            ])
            ->assertJsonPath('data.sections.1.settings.limit', 6)
            ->assertJsonPath('data.sections.1.settings.__style', ['padding_top' => 8])
            ->assertJsonPath('data.sections.2.settings.layout', 'carousel')
            ->assertJsonPath('data.sections.2.settings.blocks', []);

        $this->assertStringStartsWith('b_', $response->json('data.sections.0.settings.blocks.1.id'));
        $this->assertNotContains('b_bad', array_column($response->json('data.sections.0.settings.blocks'), 'id'));
        $this->assertArrayNotHasKey('blocks', $response->json('data.sections.1.settings'));   // no blocks schema -> dropped
        $this->assertArrayNotHasKey('__style', $response->json('data.sections.2.settings'));  // not an object -> dropped
        $this->assertArrayNotHasKey('__style', $response->json('data.sections.3.settings'));  // nothing valid left -> dropped
        $this->assertArrayNotHasKey('blocks', $response->json('data.sections.3.settings'));   // key absent stays absent

        $this->api()->getJson($this->base()."/{$id}")->assertOk()
            ->assertJsonPath('data.sections.0.settings.blocks.0.id', 'b_keep')
            ->assertJsonPath('data.sections.0.settings.__style.anchor', 'our-customers')
            ->assertJsonPath('data.sections.0.settings.layout', 'grid');
    }

    public function test_public_layout_and_blade_fallback_carry_published_blocks_and_style(): void
    {
        $id = $this->api()->getJson($this->base().'/template/home')->json('data.id');
        $this->api()->putJson($this->base()."/{$id}/sections", ['sections' => [
            ['type' => 'testimonials', 'settings' => [
                'layout' => 'carousel',
                'blocks' => [['id' => 'b_1', 'type' => 'quote', 'settings' => ['author' => 'Ali']], ['id' => 'b_2', 'type' => 'cta', 'hidden' => true, 'settings' => ['style' => 'ghost']]],
                '__style' => ['padding_top' => 48, 'container' => 'narrow', 'anchor' => 'reviews'],
                '__responsive' => ['padding_top' => ['mobile' => 24]],
            ]],
            ['type' => 'product-grid', 'settings' => ['limit' => 4, '__style' => ['hide_mobile' => true], 'unknown_key' => ['deep' => true]]],
        ]])->assertOk();

        // Draft: the public layout still serves the manifest template (which itself carries blocks).
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/layout?template=home')->assertOk()
            ->assertJsonPath('data.source', 'theme')
            ->assertJsonPath('data.sections.3.type', 'testimonials')
            ->assertJsonPath('data.sections.3.settings.blocks.0.id', 'b_seed_1');

        $this->api()->postJson($this->base()."/{$id}/publish")->assertOk();

        $this->getJson('http://nike.sellchase.com/api/v1/storefront/layout?template=home')->assertOk()
            ->assertJsonPath('data.source', 'store')
            ->assertJsonCount(2, 'data.sections')
            ->assertJsonPath('data.sections.0.type', 'testimonials')
            ->assertJsonPath('data.sections.0.settings.layout', 'carousel')
            ->assertJsonPath('data.sections.0.settings.blocks', [
                ['id' => 'b_1', 'type' => 'quote', 'hidden' => false, 'settings' => ['text' => ['en' => 'Great store', 'ar' => 'متجر رائع'], 'author' => 'Ali', 'rating' => 5, 'tags' => []]],
                ['id' => 'b_2', 'type' => 'cta', 'hidden' => true, 'settings' => ['label' => 'Shop now', 'style' => 'ghost']],
            ])
            ->assertJsonPath('data.sections.0.settings.__style', ['padding_top' => 48, 'container' => 'narrow', 'anchor' => 'reviews'])
            ->assertJsonPath('data.sections.0.settings.__responsive.padding_top.mobile', 24)
            ->assertJsonPath('data.sections.1.settings.__style.hide_mobile', true)
            ->assertJsonPath('data.sections.1.settings.unknown_key.deep', true);

        // Blade/SSR fallback: unknown keys (blocks, __style, arbitrary) are ignored, never thrown on.
        $this->get('http://nike.sellchase.com/')->assertOk()
            ->assertSee('data-section="product-grid"', false)
            ->assertDontSee('b_1', false);
    }
}

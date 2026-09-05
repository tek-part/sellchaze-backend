<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreTheme;
use App\Models\Theme;
use App\Models\User;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Services\Themes\ThemeSettingsValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** E2: translatable theme settings are stored as locale maps and flattened per locale for themes. */
class ThemeSettingsTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function schema(): array
    {
        return [
            ['id' => 'header', 'label' => 'Header', 'fields' => [
                ['id' => 'announcement', 'type' => 'text', 'default' => '', 'translatable' => true],
                ['id' => 'primary', 'type' => 'color', 'default' => '#000000'],
                ['id' => 'tagline', 'type' => 'text', 'default' => 'Hi'],
            ]],
        ];
    }

    public function test_validator_accepts_strings_or_locale_maps_for_translatable_fields(): void
    {
        $v = new ThemeSettingsValidator;
        $this->assertSame([], $v->errors(['announcement' => 'Free shipping'], $this->schema()));
        $this->assertSame([], $v->errors(['announcement' => ['en' => 'Free shipping', 'ar' => 'شحن مجاني']], $this->schema()));
        $this->assertNotEmpty($v->errors(['announcement' => ['en' => ['nested']]], $this->schema()));
        $this->assertNotEmpty($v->errors(['announcement' => 42], $this->schema()));
        // Non-translatable text fields still reject maps.
        $this->assertNotEmpty($v->errors(['tagline' => ['en' => 'x']], $this->schema()));
    }

    public function test_coerce_wraps_legacy_strings_into_the_default_bucket_and_flatten_picks_by_locale(): void
    {
        $v = new ThemeSettingsValidator;

        $legacy = $v->coerce(['announcement' => 'Free shipping'], $this->schema());
        $this->assertSame(['default' => 'Free shipping'], $legacy['announcement']);
        $this->assertSame('#000000', $legacy['primary']);

        $map = $v->coerce(['announcement' => ['en' => 'Free shipping', 'ar' => 'شحن مجاني', 'bogus key' => 'x']], $this->schema());
        $this->assertSame(['en' => 'Free shipping', 'ar' => 'شحن مجاني'], $map['announcement']);

        $this->assertSame(['default' => ''], $v->coerce([], $this->schema())['announcement']);

        $this->assertSame('شحن مجاني', $v->flatten($map, $this->schema(), 'ar', 'en')['announcement']);
        $this->assertSame('Free shipping', $v->flatten($map, $this->schema(), 'fr', 'en')['announcement']);
        $this->assertSame('Free shipping', $v->flatten($legacy, $this->schema(), 'ar', 'en')['announcement']);
        $this->assertSame('#000000', $v->flatten($map, $this->schema(), 'ar', 'en')['primary']);
    }

    public function test_resolver_returns_flat_settings_for_the_locale_plus_settings_i18n(): void
    {
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/modern/theme.json'));
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar'],
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $service = app(StoreThemeService::class);
        $theme = Theme::query()->where('key', 'modern')->firstOrFail();
        $install = $service->install($store, $theme, app(ThemeRegistry::class)->resolveThemeVersion($theme));
        $service->updateSettings($install, ['announcement' => ['en' => 'Free shipping', 'ar' => 'شحن مجاني']]);
        $service->publish($store, $install->fresh());
        $service->activate($store, $install->fresh());

        $stored = StoreTheme::query()->find($install->id);
        $this->assertSame(['en' => 'Free shipping', 'ar' => 'شحن مجاني'], $stored->settings['announcement']);

        $resolver = app(ThemeResolver::class);
        $ar = $resolver->resolve($store, 'ar');
        $this->assertSame('شحن مجاني', $ar['settings']['announcement']);
        $this->assertSame(['en' => 'Free shipping', 'ar' => 'شحن مجاني'], $ar['settings_i18n']['announcement']);
        $this->assertSame('Free shipping', $resolver->resolve($store, 'en')['settings']['announcement']);

        // Public payloads carry flat scalars for the request locale.
        $this->getJson('http://nike.sellchase.com/api/v1/storefront?lang=ar')
            ->assertOk()
            ->assertJsonPath('theme.settings.announcement', 'شحن مجاني')
            ->assertJsonPath('theme.settings_i18n.announcement.en', 'Free shipping');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/context?template=home&lang=en')
            ->assertOk()
            ->assertJsonPath('theme.settings.announcement', 'Free shipping')
            ->assertJsonPath('locale.current', 'en');
    }

    public function test_manifests_flag_text_settings_as_translatable(): void
    {
        foreach (['atlas', 'modern', 'verde'] as $key) {
            $manifest = json_decode(file_get_contents(resource_path("themes/{$key}/theme.json")), true);
            $field = collect($manifest['settings_schema'])->flatMap(fn ($g) => $g['fields'])->firstWhere('id', 'announcement');
            $this->assertTrue($field['translatable'] ?? false, "{$key}.announcement");
        }
        $aurora = json_decode(file_get_contents(resource_path('themes/aurora/theme.json')), true);
        $this->assertTrue(collect($aurora['settings_schema'])->flatMap(fn ($g) => $g['fields'])->firstWhere('id', 'headline')['translatable']);
    }
}

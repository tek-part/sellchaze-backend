<?php

namespace Tests\Feature;

use App\Services\Themes\ThemeRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class ThemeManifestValidationTest extends TestCase
{
    private ThemeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ThemeRegistry;
    }

    private function validManifest(): array
    {
        return [
            'key' => 'demo',
            'name' => 'Demo',
            'version' => '1.0.0',
            'settings_schema' => [
                ['id' => 'colors', 'label' => 'Colors', 'fields' => [
                    ['id' => 'primary', 'type' => 'color', 'default' => '#000000'],
                ]],
            ],
            'sections_schema' => [
                'hero' => ['label' => 'Hero', 'settings' => []],
                'product-grid' => ['label' => 'Product grid', 'settings' => []],
                'category-header' => ['label' => 'Category header', 'settings' => []],
                'product-details' => ['label' => 'Product details', 'settings' => []],
            ],
            'templates' => [
                'home' => ['sections' => [['type' => 'hero'], ['type' => 'product-grid']]],
                'product' => ['sections' => [['type' => 'product-details']]],
                'category' => ['sections' => [['type' => 'category-header'], ['type' => 'product-grid']]],
            ],
        ];
    }

    public function test_a_valid_manifest_has_no_errors(): void
    {
        $this->assertSame([], $this->registry->validate($this->validManifest()));
    }

    public function test_the_shipped_default_theme_is_valid(): void
    {
        $key = ThemeRegistry::defaultThemeKey();
        $this->assertSame('naseem', $key);
        $manifest = json_decode(file_get_contents(resource_path("themes/storefront/{$key}.json")), true);
        $this->assertSame([], $this->registry->validate($manifest));
    }

    public function test_all_react_storefront_manifests_are_valid_and_keyed_consistently(): void
    {
        $keys = [];
        foreach (ThemeRegistry::manifestPaths() as $path) {
            $key = basename($path, '.json');
            $manifest = json_decode(file_get_contents($path), true);
            $this->assertSame($key, $manifest['key'], "Manifest file name and key differ: {$path}");
            $this->assertSame([], $this->registry->validate($manifest), "Invalid manifest: {$key}");
            $this->assertSame("builtin:{$key}@{$manifest['version']}", $manifest['bundle_url']);
            $keys[] = $key;
        }
        $this->assertSame(['bazaar', 'fresh', 'naseem', 'sahra', 'techno'], $keys);

        // The legacy manifests are gone for good.
        foreach (['default', 'aurora', 'modern', 'atlas', 'verde'] as $legacy) {
            $this->assertDirectoryDoesNotExist(resource_path("themes/{$legacy}"));
        }
        foreach (['luxury-fashion', 'voltage', 'hearth', 'rouge'] as $legacy) {
            $this->assertFileDoesNotExist(resource_path("themes/storefront/{$legacy}.json"));
        }
    }

    public function test_missing_required_field_is_rejected(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['version']);
        $this->assertContains('missing required field: version', $this->registry->validate($manifest));
    }

    public function test_non_semver_version_is_rejected(): void
    {
        $manifest = $this->validManifest();
        $manifest['version'] = 'v1';
        $errors = $this->registry->validate($manifest);
        $this->assertContains('version must be semver (MAJOR.MINOR.PATCH)', $errors);
    }

    public function test_template_referencing_unknown_section_is_rejected(): void
    {
        $manifest = $this->validManifest();
        $manifest['templates']['home']['sections'][] = ['type' => 'does-not-exist'];
        $errors = $this->registry->validate($manifest);
        $this->assertContains("template 'home' references unknown section type 'does-not-exist'", $errors);
    }

    public function test_missing_required_template_is_rejected(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['templates']['product']);
        $this->assertContains('missing required template: product', $this->registry->validate($manifest));
    }

    public function test_register_throws_on_invalid_manifest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->register(['key' => 'x']);
    }
}

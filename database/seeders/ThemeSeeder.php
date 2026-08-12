<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\Theme;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Illuminate\Database\Seeder;

/**
 * Registers the first-party marketplace and backfills stores without an active install.
 */
class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(ThemeRegistry::class);
        $registry->registerFromFile(resource_path('themes/default/theme.json'));
        $registry->registerFromFile(resource_path('themes/default/v1.1.0.json'));   // Phase 4D: 2nd version
        $registry->registerFromFile(resource_path('themes/aurora/theme.json'));      // Phase 4D: 2nd theme
        foreach (['luxury-fashion', 'voltage', 'hearth', 'rouge'] as $key) {
            $registry->registerFromFile(resource_path("themes/storefront/{$key}.json"));
        }
        $registry->registerFromFile(resource_path('themes/modern/theme.json'));      // Theme 01: premium theme-driven
        $registry->registerFromFile(resource_path('themes/atlas/theme.json'));       // Industrial/B2B
        $registry->registerFromFile(resource_path('themes/verde/theme.json'));       // Food & agriculture

        $installer = app(StoreThemeService::class);
        Store::query()
            ->whereDoesntHave('storeThemes', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->each(fn (Store $store) => $installer->installAndActivateDefault($store));

        $this->command->info('Themes seeded: '.Theme::count().'; active installs='.StoreTheme::where('status', 'active')->count());
    }
}

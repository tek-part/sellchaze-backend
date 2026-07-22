<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Illuminate\Database\Seeder;

/**
 * Registers the first-party Default theme and backfills any existing store that
 * has no active theme install.
 */
class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(ThemeRegistry::class);
        $registry->registerFromFile(resource_path('themes/default/theme.json'));
        $registry->registerFromFile(resource_path('themes/default/v1.1.0.json'));   // Phase 4D: 2nd version
        $registry->registerFromFile(resource_path('themes/aurora/theme.json'));      // Phase 4D: 2nd theme
        $registry->registerFromFile(resource_path('themes/modern/theme.json'));      // Theme 01: premium theme-driven

        $installer = app(StoreThemeService::class);
        Store::query()
            ->whereDoesntHave('storeThemes', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->each(fn (Store $store) => $installer->installAndActivateDefault($store));

        $this->command->info('Themes seeded: default registered; active installs='.\App\Models\StoreTheme::where('status', 'active')->count());
    }
}

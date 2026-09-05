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
        // Same discovery as `php artisan themes:register` so the two never diverge.
        foreach (ThemeRegistry::manifestPaths() as $path) {
            $registry->registerFromFile($path);
        }

        $installer = app(StoreThemeService::class);
        Store::query()
            ->whereDoesntHave('storeThemes', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->each(fn (Store $store) => $installer->installAndActivateDefault($store));

        $this->command->info('Themes seeded: '.Theme::count().'; active installs='.StoreTheme::where('status', 'active')->count());
    }
}

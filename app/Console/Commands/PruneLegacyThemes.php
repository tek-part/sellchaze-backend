<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\StoreThemeActivation;
use App\Models\StoreThemeLicense;
use App\Models\StoreThemeRevision;
use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Models\ThemeLicensePurchase;
use App\Models\ThemeLicensePurchaseEvent;
use App\Models\ThemeStatusChange;
use App\Models\ThemeVersion;
use App\Models\ThemeVersionStatusChange;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes every registered theme that is not one of the first-party storefront
 * themes (naseem, bazaar, sahra, fresh, techno). Stores whose ACTIVE install points
 * at a legacy theme are moved to the configured default theme first (their legacy
 * settings are not preserved), then every row that references the theme is deleted
 * in FK order inside one transaction per theme. Idempotent: a second run finds
 * nothing to prune. Run `themes:register` first so the default theme exists.
 */
class PruneLegacyThemes extends Command
{
    /** The only theme keys allowed to survive. */
    public const ALLOWED_KEYS = ['naseem', 'bazaar', 'sahra', 'fresh', 'techno'];

    protected $signature = 'themes:prune-legacy
        {--dry-run : Report what would be removed without changing anything}';

    protected $description = 'Delete every theme that is not a first-party storefront theme (naseem, bazaar, sahra, fresh, techno); stores using one are moved to the default theme.';

    public function handle(ThemeRegistry $registry, StoreThemeService $installer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $defaultKey = ThemeRegistry::defaultThemeKey();
        if (! in_array($defaultKey, self::ALLOWED_KEYS, true)) {
            $this->error("Configured default theme '{$defaultKey}' is not one of: ".implode(', ', self::ALLOWED_KEYS).'.');

            return self::FAILURE;
        }

        /** @var Collection<int, Theme> $legacy */
        $legacy = Theme::query()->whereNotIn('key', self::ALLOWED_KEYS)->orderBy('key')->get();
        if ($legacy->isEmpty()) {
            $this->info('No legacy themes registered; nothing to prune.');

            return self::SUCCESS;
        }

        $default = $registry->defaultTheme();
        $defaultVersion = $default ? $registry->resolveThemeVersion($default) : null;

        $rows = [];
        $failed = 0;
        foreach ($legacy as $theme) {
            $storeIds = $this->affectedStoreIds($theme);
            $counts = $this->counts($theme);

            $outcome = $dryRun ? 'would delete' : 'deleted';
            if ($dryRun) {
                foreach ($storeIds as $storeId) {
                    $this->line("  store {$storeId}: {$theme->key} -> {$defaultKey} (dry run, not applied)");
                }
            } else {
                if ($storeIds !== [] && ! $defaultVersion) {
                    $this->error("Default theme '{$defaultKey}' is not registered; run `themes:register` first. Skipping '{$theme->key}'.");
                    $failed++;
                    $outcome = 'skipped';
                } else {
                    try {
                        foreach ($storeIds as $storeId) {
                            $this->moveStoreToDefault($installer, $storeId, $theme, $defaultKey);
                        }
                        DB::transaction(fn () => $this->purge($theme));
                    } catch (Throwable $e) {
                        report($e);
                        $this->error("Failed to prune '{$theme->key}': {$e->getMessage()}");
                        $failed++;
                        $outcome = 'failed';
                    }
                }
            }

            $rows[] = [
                $theme->key,
                count($storeIds),
                $counts['installs'],
                $counts['activations'],
                $counts['revisions'],
                $counts['licenses'],
                $counts['purchases'],
                $counts['assets'],
                $counts['status_changes'],
                $counts['versions'],
                $outcome,
            ];
        }

        $this->table(
            ['Key', 'Stores moved', 'Installs', 'Activations', 'Revisions', 'Licenses', 'Purchases', 'Assets', 'Status changes', 'Versions', 'Outcome'],
            $rows,
        );
        $this->info(sprintf(
            '%s %d legacy theme(s)%s; stores are moved to the default theme "%s". Remaining: %s.',
            $dryRun ? 'Dry run — would prune' : 'Pruned',
            count($rows) - $failed,
            $failed ? " ({$failed} failed)" : '',
            $defaultKey,
            Theme::query()->orderBy('key')->pluck('key')->implode(', ') ?: '(none)',
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Stores that must be moved off the theme: those whose ACTIVE install is this theme,
     * plus stores whose denormalised `stores.theme_id` still points at it (no FK there).
     *
     * @return list<int>
     */
    private function affectedStoreIds(Theme $theme): array
    {
        $active = StoreTheme::query()->where('theme_id', $theme->id)->where('status', 'active')->pluck('store_id');
        $dangling = Store::query()->where('theme_id', $theme->id)->pluck('id');

        return $active->merge($dangling)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    private function moveStoreToDefault(StoreThemeService $installer, int $storeId, Theme $from, string $defaultKey): void
    {
        $store = Store::query()->find($storeId);
        if (! $store) {
            return;
        }
        $install = $installer->installAndActivateDefault($store);
        if (! $install) {
            throw new \RuntimeException("Default theme '{$defaultKey}' could not be activated for store {$storeId}.");
        }

        $this->line("  store {$storeId}: {$from->key} -> {$defaultKey}");
        Log::info('themes:prune-legacy moved a store to the default theme', [
            'store_id' => $storeId,
            'from_theme' => $from->key,
            'to_theme' => $defaultKey,
        ]);
    }

    /** @return array{installs:int,activations:int,revisions:int,licenses:int,purchases:int,assets:int,status_changes:int,versions:int} */
    private function counts(Theme $theme): array
    {
        $installIds = StoreTheme::query()->where('theme_id', $theme->id)->pluck('id');

        return [
            'installs' => $installIds->count(),
            'activations' => StoreThemeActivation::query()->where('theme_id', $theme->id)->count(),
            'revisions' => StoreThemeRevision::query()->whereIn('store_theme_id', $installIds)->count(),
            'licenses' => StoreThemeLicense::query()->where('theme_id', $theme->id)->count(),
            'purchases' => ThemeLicensePurchase::query()->where('theme_id', $theme->id)->count(),
            'assets' => ThemeAsset::query()->where('theme_id', $theme->id)->count(),
            'status_changes' => ThemeStatusChange::query()->where('theme_id', $theme->id)->count(),
            'versions' => ThemeVersion::query()->where('theme_id', $theme->id)->count(),
        ];
    }

    /** Delete every row referencing the theme, children before parents, then the theme itself. */
    private function purge(Theme $theme): void
    {
        $installIds = StoreTheme::query()->where('theme_id', $theme->id)->pluck('id');
        StoreThemeRevision::query()->whereIn('store_theme_id', $installIds)->delete();
        StoreTheme::query()->where('theme_id', $theme->id)->delete();

        StoreThemeActivation::query()->where('theme_id', $theme->id)->delete();
        StoreThemeLicense::query()->where('theme_id', $theme->id)->delete();

        $purchaseIds = ThemeLicensePurchase::query()->where('theme_id', $theme->id)->pluck('id');
        ThemeLicensePurchaseEvent::query()->whereIn('theme_license_purchase_id', $purchaseIds)->delete();
        ThemeLicensePurchase::query()->where('theme_id', $theme->id)->delete(); // restrictOnDelete: must go before the theme

        ThemeAsset::query()->where('theme_id', $theme->id)->delete();
        ThemeStatusChange::query()->where('theme_id', $theme->id)->delete();

        $versionIds = ThemeVersion::query()->where('theme_id', $theme->id)->pluck('id');
        ThemeVersionStatusChange::query()->whereIn('theme_version_id', $versionIds)->delete();
        Theme::query()->whereKey($theme->id)->update(['latest_version_id' => null]);
        ThemeVersion::query()->where('theme_id', $theme->id)->delete();

        // Any store still pointing its cache column at the theme (none after the move above).
        Store::query()->where('theme_id', $theme->id)->update(['theme_id' => null, 'theme_settings' => null]);

        Theme::query()->whereKey($theme->id)->delete();
    }
}

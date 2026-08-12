<?php

namespace App\Services\Themes;

use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\StoreThemeActivation;
use App\Models\StoreThemeRevision;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Store-side theme lifecycle: install, preview, activate (with one-active
 * guarantee + history), edit settings, and rollback. Keeps the
 * stores.theme_id / stores.theme_settings cache and the full-page cache in sync.
 */
class StoreThemeService
{
    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly ThemeSettingsValidator $validator,
        private readonly ThemePreviewToken $previewToken,
        private readonly ThemeCompatibility $compatibility,
        private readonly ThemeSettingsMigrator $migrator,
        private readonly StorefrontUrlGenerator $urls,
    ) {}

    /** Install (idempotent) a theme version for a store, seeding settings defaults. */
    public function install(Store $store, Theme $theme, ThemeVersion $version): StoreTheme
    {
        $this->compatibility->assertCompatible($version); // Task 2: cannot install incompatible

        return StoreTheme::updateOrCreate(
            ['store_id' => $store->id, 'theme_id' => $theme->id],
            [
                'theme_version_id' => $version->id,
                'settings' => $this->settingsDefaults($version),
                'status' => 'installed',
                'installed_at' => now(),
            ],
        );
    }

    /**
     * Make an install the single active theme, refresh caches, and record history.
     */
    public function activate(
        Store $store,
        StoreTheme $install,
        ?int $actorId = null,
        string $action = 'activate',
        ?int $rollbackFrom = null,
        ?int $rollbackTo = null,
    ): StoreTheme {
        $version = ThemeVersion::query()->find($install->theme_version_id);
        if ($version) {
            $this->compatibility->assertCompatible($version); // Task 2: cannot activate incompatible
        }

        // Atomic: deactivate the previous active install, activate this one, mirror
        // the theme cache columns onto the store, and record the audit row — all or
        // nothing, so a partial/inconsistent activation state can never persist.
        DB::transaction(function () use ($store, $install, $action, $actorId, $rollbackFrom, $rollbackTo) {
            StoreTheme::query()
                ->where('store_id', $store->id)
                ->where('id', '!=', $install->id)
                ->where('status', 'active')
                ->update(['status' => 'installed']);

            $install->status = 'active';
            $install->save();

            $store->forceFill([
                'theme_id' => $install->theme_id,
                'theme_settings' => $install->settings,
            ])->save();

            StoreThemeActivation::create([
                'store_id' => $store->id,
                'theme_id' => $install->theme_id,
                'theme_version_id' => $install->theme_version_id,
                'settings' => $install->settings,
                'action' => $action,
                'activated_by' => $actorId,
                'activated_at' => now(),
                'rollback_from_version_id' => $rollbackFrom,
                'rollback_to_version_id' => $rollbackTo,
            ]);
        });

        // After commit: instant full-page cache invalidation -> SSR uses the new theme.
        app(StorefrontPageCache::class)->flushStore($store->id);

        return $install;
    }

    /** Validate + persist settings for an install; refresh caches if it is active. */
    public function updateSettings(
        StoreTheme $install,
        array $settings,
        ?int $actorId = null,
        string $source = 'manual',
    ): StoreTheme {
        $version = ThemeVersion::query()->find($install->theme_version_id);
        $schema = $version->settings_schema ?? [];

        $install->settings = $this->validator->coerce($settings, $schema);
        $install->save();
        $this->recordRevision($install, $actorId, $source);

        if ($install->status === 'active') {
            $install->store?->forceFill(['theme_settings' => $install->settings])->save(); // triggers page-cache flush
            // Keep the active activation snapshot in sync so rollback restores the
            // exact current settings (backup), not the values from activation time.
            StoreThemeActivation::query()
                ->where('store_id', $install->store_id)
                ->latest('id')->first()?->update(['settings' => $install->settings]);
        }

        return $install;
    }

    public function restoreRevision(StoreTheme $install, StoreThemeRevision $revision, ?int $actorId = null): StoreTheme
    {
        if ($revision->store_theme_id !== $install->id || $revision->store_id !== $install->store_id) {
            throw new RuntimeException('Theme revision does not belong to this install.');
        }

        return $this->updateSettings($install, $revision->settings ?? [], $actorId, 'restore');
    }

    private function recordRevision(StoreTheme $install, ?int $actorId, string $source): void
    {
        $settings = $install->settings ?? [];
        ksort($settings);
        $checksum = hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
        $latestChecksum = $install->revisions()->latest('id')->value('checksum');
        if ($latestChecksum === $checksum) {
            return;
        }
        $install->revisions()->create([
            'store_id' => $install->store_id,
            'theme_version_id' => $install->theme_version_id,
            'created_by_user_id' => $actorId,
            'source' => $source,
            'settings' => $settings,
            'checksum' => $checksum,
        ]);
    }

    /**
     * Roll back to the store's previous active state (theme version + settings).
     */
    public function rollback(Store $store, ?int $actorId = null): StoreTheme
    {
        $recent = StoreThemeActivation::query()
            ->where('store_id', $store->id)
            ->orderByDesc('id')
            ->take(2)
            ->get();

        if ($recent->count() < 2) {
            throw new RuntimeException('No previous theme to roll back to.');
        }

        $current = $recent[0];
        $previous = $recent[1];

        $install = StoreTheme::updateOrCreate(
            ['store_id' => $store->id, 'theme_id' => $previous->theme_id],
            [
                'theme_version_id' => $previous->theme_version_id,
                'settings' => $previous->settings ?? [],
                'status' => 'installed',
                'installed_at' => now(),
            ],
        );

        return $this->activate(
            $store,
            $install,
            $actorId,
            'rollback',
            $current->theme_version_id,
            $previous->theme_version_id,
        );
    }

    /**
     * Upgrade a store's installed theme to a newer version (Task 3): validate it
     * is newer + compatible, migrate settings, then persist. If the theme was
     * active it is re-activated (history + cache flush); the prior activation row
     * retains the old version + settings, so rollback restores them.
     */
    public function upgrade(Store $store, Theme $theme, ?string $version, ?int $actorId = null): StoreTheme
    {
        $install = StoreTheme::query()->where('store_id', $store->id)->where('theme_id', $theme->id)->first();
        if (! $install) {
            throw new RuntimeException('Theme is not installed for this store.');
        }
        $current = ThemeVersion::query()->find($install->theme_version_id);
        $target = $this->registry->resolveThemeVersion($theme, $version);
        if (! $target) {
            throw new RuntimeException('Target theme version not found.');
        }
        if (version_compare($target->version, $current?->version ?? '0.0.0', '<=')) {
            throw new RuntimeException('Target version is not newer than the current version.');
        }
        $this->compatibility->assertCompatible($target);

        $migrated = $this->migrator->migrate($theme->key, $current?->version ?? '', $target->version, $install->settings ?? []);
        $migrated = $this->validator->coerce($migrated, $target->settings_schema ?? []);

        $wasActive = $install->status === 'active';
        $install->theme_version_id = $target->id;
        $install->settings = $migrated;
        $install->save();

        if ($wasActive) {
            return $this->activate($store, $install, $actorId);
        }

        app(StorefrontPageCache::class)->flushStore($store->id);

        return $install;
    }

    /** Generate a signed, expiring, environment-openable theme preview URL. */
    public function previewUrl(Store $store, StoreTheme $install, int $ttlSeconds = 1800, int $versionId = 0): string
    {
        $token = $this->previewToken->make($store->id, $install->id, $ttlSeconds, $versionId);

        return (string) $this->urls->previewUrl($store, $token, '/');
    }

    /**
     * Install + activate the first-party Default theme. No-op (returns null) if
     * no Default theme is registered, so store creation never depends on themes.
     */
    public function installAndActivateDefault(Store $store, ?int $actorId = null): ?StoreTheme
    {
        $theme = $this->registry->defaultTheme();
        if (! $theme) {
            return null;
        }
        $version = $this->registry->resolveThemeVersion($theme);
        if (! $version) {
            return null;
        }

        return $this->activate($store, $this->install($store, $theme, $version), $actorId);
    }

    /** Flatten settings_schema group defaults into a { fieldId: default } map. */
    public function settingsDefaults(ThemeVersion $version): array
    {
        $defaults = [];
        foreach (($version->settings_schema ?? []) as $group) {
            foreach (($group['fields'] ?? []) as $field) {
                if (isset($field['id'])) {
                    $defaults[$field['id']] = $field['default'] ?? null;
                }
            }
        }

        return $defaults;
    }
}

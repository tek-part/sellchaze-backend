<?php

namespace App\Services\Themes;

/**
 * Theme settings migration registry (Task 4). When a theme's settings_schema
 * changes between versions (e.g. rename primary_color -> brand_primary), a
 * registered migration transforms a store's settings so nothing is lost on upgrade.
 *
 * Registered as a singleton; migrations are declared in AppServiceProvider::boot().
 * The upgrade flow snapshots the pre-migration settings into activation history
 * (the backup), so rollback restores the exact prior settings.
 */
class ThemeSettingsMigrator
{
    /** @var array<string, array<string, array<string, callable>>> [themeKey][from][to] => transform */
    private array $migrations = [];

    public function register(string $themeKey, string $from, string $to, callable $transform): void
    {
        $this->migrations[$themeKey][$from][$to] = $transform;
    }

    public function hasMigration(string $themeKey, string $from, string $to): bool
    {
        return isset($this->migrations[$themeKey][$from][$to]);
    }

    /**
     * Transform settings from one version to another. Single-hop for the
     * foundation; an unmigrated pair passes settings through unchanged (new/removed
     * fields are then reconciled by the schema validator on the target version).
     */
    public function migrate(string $themeKey, string $from, string $to, array $settings): array
    {
        if ($from === $to) {
            return $settings;
        }
        $transform = $this->migrations[$themeKey][$from][$to] ?? null;

        return $transform ? (array) $transform($settings) : $settings;
    }

    /** Convenience rename helper for simple field renames. */
    public static function rename(array $map): callable
    {
        return function (array $settings) use ($map): array {
            foreach ($map as $old => $new) {
                if (array_key_exists($old, $settings) && ! array_key_exists($new, $settings)) {
                    $settings[$new] = $settings[$old];
                }
                unset($settings[$old]);
            }

            return $settings;
        };
    }
}

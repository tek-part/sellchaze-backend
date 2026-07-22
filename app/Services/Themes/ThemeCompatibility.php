<?php

namespace App\Services\Themes;

use App\Models\ThemeVersion;
use RuntimeException;

/**
 * Validates a theme version against the running platform (Task 2). Checked on
 * install, activate, and upgrade — a store can never install an incompatible version.
 */
class ThemeCompatibility
{
    public function platformVersion(): string
    {
        return (string) config('sellchase.platform.version', '1.0.0');
    }

    /** @return string[] compatibility errors (empty = compatible) */
    public function errors(ThemeVersion $version): array
    {
        $errors = [];
        $platform = $this->platformVersion();

        if ($version->min_platform_version && version_compare($platform, $version->min_platform_version, '<')) {
            $errors[] = "requires platform >= {$version->min_platform_version} (running {$platform})";
        }
        if ($version->max_platform_version && version_compare($platform, $version->max_platform_version, '>')) {
            $errors[] = "requires platform <= {$version->max_platform_version} (running {$platform})";
        }

        $have = (array) config('sellchase.platform.features', []);
        foreach (($version->supported_features ?? []) as $feature) {
            if (! in_array($feature, $have, true)) {
                $errors[] = "unsupported feature: {$feature}";
            }
        }

        return $errors;
    }

    public function isCompatible(ThemeVersion $version): bool
    {
        return $this->errors($version) === [];
    }

    public function assertCompatible(ThemeVersion $version): void
    {
        $errors = $this->errors($version);
        if ($errors !== []) {
            throw new RuntimeException('Theme version is incompatible: '.implode('; ', $errors));
        }
    }
}

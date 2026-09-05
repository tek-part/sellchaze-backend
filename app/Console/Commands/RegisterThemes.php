<?php

namespace App\Console\Commands;

use App\Services\Themes\ThemeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Registers (idempotently) every first-party theme manifest: the legacy hand-written
 * themes plus every generated `resources/themes/storefront/<key>.json` written by the
 * frontend's `npm run themes:manifests`. Uses the same discovery as ThemeSeeder.
 */
class RegisterThemes extends Command
{
    protected $signature = 'themes:register
        {--only= : Comma-separated theme keys to register (others are skipped)}
        {--path=* : Extra manifest file(s) to register in addition to the discovered ones}';

    protected $description = 'Register theme manifests (resources/themes/**.json) into the theme registry.';

    public function handle(ThemeRegistry $registry): int
    {
        $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
        $paths = ThemeRegistry::manifestPaths();
        foreach ((array) $this->option('path') as $extra) {
            $extra = is_string($extra) ? (str_starts_with($extra, '/') ? $extra : base_path($extra)) : '';
            if ($extra !== '' && ! in_array($extra, $paths, true)) {
                $paths[] = $extra;
            }
        }

        $rows = [];
        $failed = 0;
        foreach ($paths as $path) {
            $manifest = File::exists($path) ? json_decode((string) File::get($path), true) : null;
            $key = is_array($manifest) ? (string) ($manifest['key'] ?? '') : '';
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }

            try {
                $theme = $registry->registerFromFile($path);
                $rows[] = [$theme->key, (string) ($manifest['version'] ?? ''), count($manifest['sections_schema'] ?? []), $this->relative($path)];
            } catch (InvalidArgumentException $e) {
                $failed++;
                $this->error("{$this->relative($path)}: {$e->getMessage()}");
            }
        }

        if ($rows === []) {
            $this->warn('No theme manifests matched.');
        } else {
            $this->table(['Key', 'Version', 'Sections', 'Manifest'], $rows);
        }
        $this->info(sprintf('Registered %d theme manifest(s)%s.', count($rows), $failed ? ", {$failed} failed" : ''));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, base_path()) ? ltrim(substr($path, strlen(base_path())), '/') : $path;
    }
}

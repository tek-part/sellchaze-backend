<?php

namespace App\Services\Themes;

use App\Models\Theme;
use App\Models\ThemeVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class ThemePackageService
{
    public function __construct(private readonly ThemeRegistry $registry) {}

    public function upload(Theme $theme, UploadedFile $manifestFile, UploadedFile $bundle, ?int $actorId): ThemeVersion
    {
        if ($manifestFile->getSize() > 1024 * 1024 || strtolower($manifestFile->getClientOriginalExtension()) !== 'json') {
            throw new RuntimeException('Theme manifest must be a JSON file no larger than 1 MB.');
        }
        if ($bundle->getSize() > 10 * 1024 * 1024 || ! in_array(strtolower($bundle->getClientOriginalExtension()), ['js', 'mjs'], true)) {
            throw new RuntimeException('Theme bundle must be a JavaScript file no larger than 10 MB.');
        }

        $manifestJson = file_get_contents($manifestFile->getPathname());
        $manifest = json_decode((string) $manifestJson, true);
        if (! is_array($manifest)) {
            throw new RuntimeException('Theme manifest is not valid JSON.');
        }
        if (($manifest['key'] ?? null) !== $theme->key) {
            throw new RuntimeException('Manifest theme key does not match this theme.');
        }

        $errors = $this->registry->validate($manifest);
        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid theme manifest: '.implode('; ', $errors));
        }
        if (ThemeVersion::query()->where('theme_id', $theme->id)->where('version', $manifest['version'])->exists()) {
            throw new RuntimeException('This immutable theme version already exists. Upload a new semantic version.');
        }

        $bundleContents = file_get_contents($bundle->getPathname());
        if (! is_string($bundleContents) || $bundleContents === '' || str_contains($bundleContents, "\0") || str_contains($bundleContents, '<?php')) {
            throw new RuntimeException('Theme bundle content is invalid.');
        }

        $disk = (string) config('sellchase.theme_bundles_disk', 'public');
        $sha256 = hash('sha256', $bundleContents);
        $path = 'theme-bundles/'.$theme->key.'/'.$manifest['version'].'/'.$sha256.'.js';
        if (! Storage::disk($disk)->put($path, $bundleContents, ['visibility' => 'public'])) {
            throw new RuntimeException('Theme bundle could not be stored.');
        }

        try {
            $version = ThemeVersion::create([
                'theme_id' => $theme->id,
                'version' => $manifest['version'],
                'status' => 'draft',
                'settings_schema' => $manifest['settings_schema'],
                'sections_schema' => $manifest['sections_schema'],
                'templates' => $manifest['templates'],
                'bundle_url' => null,
                'bundle_disk' => $disk,
                'bundle_path' => $path,
                'bundle_checksum' => $sha256,
                'bundle_integrity' => 'sha384-'.base64_encode(hash('sha384', $bundleContents, true)),
                'bundle_size' => strlen($bundleContents),
                'manifest_checksum' => hash('sha256', (string) $manifestJson),
                'min_platform_version' => $manifest['min_platform_version'] ?? null,
                'max_platform_version' => $manifest['max_platform_version'] ?? null,
                'supported_features' => $manifest['supported_features'] ?? null,
                'changelog' => $manifest['changelog'] ?? null,
                'uploaded_by_user_id' => $actorId,
                'published_at' => null,
            ]);
            $version->forceFill([
                'bundle_url' => '/theme-bundles/'.$version->id.'/'.$sha256.'.js',
            ])->save();

            return $version;
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }
}

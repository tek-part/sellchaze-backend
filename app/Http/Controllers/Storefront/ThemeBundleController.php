<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ThemeBundleController extends Controller
{
    public function show(ThemeVersion $version, string $checksum): StreamedResponse
    {
        abort_unless(in_array($version->status, ['published', 'deprecated'], true), 404);
        abort_unless($version->bundle_path && $version->bundle_checksum, 404);
        abort_unless(hash_equals($version->bundle_checksum, strtolower($checksum)), 404);

        $disk = Storage::disk($version->bundle_disk ?: 'public');
        abort_unless($disk->exists($version->bundle_path), 404);
        $stream = $disk->readStream($version->bundle_path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Content-Length' => (string) ($version->bundle_size ?: $disk->size($version->bundle_path)),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$version->bundle_checksum.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}

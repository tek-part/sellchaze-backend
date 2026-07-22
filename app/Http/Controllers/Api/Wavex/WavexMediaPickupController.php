<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WavexMediaPickupController extends Controller
{
    /**
     * Signed, short-lived URL for Wawp to fetch uploaded media (must be reachable from Wawp servers — set APP_URL to a public HTTPS origin in production).
     */
    public function show(Request $request, string $token): StreamedResponse
    {
        if (str_contains($token, '/') || str_contains($token, '\\') || str_contains($token, '..')) {
            abort(404);
        }

        $path = 'wavex-pickup/'.$token;
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $meta = Cache::get('wavex_pickup_meta:'.$token);
        $mime = is_array($meta) ? (string) ($meta['mime'] ?? 'application/octet-stream') : 'application/octet-stream';

        return Storage::disk('local')->response($path, $token, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

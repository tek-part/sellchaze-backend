<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Services\Media\ImageDerivatives;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** First request for a missing derivative: render it, then Apache serves the file directly. */
class ImageDerivativeController extends Controller
{
    public function __invoke(ImageDerivatives $images, int $width, string $path): BinaryFileResponse
    {
        $file = $images->render($width, substr($path, 0, -strlen('.webp')));
        abort_unless($file, 404);

        return response()->file($file, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

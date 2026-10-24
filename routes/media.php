<?php

use App\Http\Controllers\Media\ImageDerivativeController;
use Illuminate\Support\Facades\Route;

// Resized WebP images. No session or cookies: these are public, cacheable assets.
Route::get('/storage/_img/w{width}/{path}', ImageDerivativeController::class)
    ->where(['width' => '[0-9]+', 'path' => '[A-Za-z0-9_\-./%]+\.webp'])
    ->middleware('throttle:media-derivatives');

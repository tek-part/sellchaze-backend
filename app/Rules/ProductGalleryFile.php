<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

final class ProductGalleryFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Upload an image or an MP4 video.');

            return;
        }
        $video = $value->getMimeType() === 'video/mp4';
        $rules = $video
            ? ['file', 'mimetypes:video/mp4', 'extensions:mp4', 'max:51200']
            : ['image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:10240'];
        if (Validator::make(['file' => $value], ['file' => $rules])->fails()) {
            $fail('Use JPG, PNG or WebP images up to 10 MB, or MP4 videos up to 50 MB.');
        }
    }
}

<?php

namespace App\Http\Requests\Concerns;

use App\Rules\ProductGalleryFile;
use App\Support\Localization\TranslationRules;
use App\Support\ProductOptionDisplay;

trait StoreCatalogFields
{
    protected function prepareForValidation(): void
    {
        TranslationRules::decodeRequest($this);
        if (is_string($this->input('option_display'))) {
            $decoded = json_decode($this->input('option_display'), true);
            if (is_array($decoded)) {
                $this->merge(['option_display' => $decoded]);
            }
        }
    }

    private function catalogFields(): array
    {
        return [
            'cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:999999.999', 'decimal:0,3'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'remove_image' => ['sometimes', 'boolean'],
            'gallery' => ['sometimes', 'array', 'max:12'],
            'gallery.*' => ['required', new ProductGalleryFile],
            'media_order' => ['sometimes', 'array', 'max:100'],
            'media_order.*' => ['integer', 'distinct'],
            'remove_media_ids' => ['sometimes', 'array', 'max:100'],
            'remove_media_ids.*' => ['integer', 'distinct'],
        ] + ProductOptionDisplay::rules() + TranslationRules::for(['name', 'description', 'short_description'], null, ['name' => 255, 'description' => 20000, 'short_description' => 500]);
    }
}

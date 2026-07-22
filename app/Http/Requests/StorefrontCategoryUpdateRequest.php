<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorefrontCategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorefrontCategoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by ScopeToStore middleware (store ownership)
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'description' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorefrontProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by ScopeToStore middleware (store ownership)
    }

    public function rules(): array
    {
        $storeId = app(CurrentStore::class)->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'sku' => ['nullable', 'string', 'max:120', Rule::unique('products', 'sku')->where('store_id', $storeId)],
            'barcode' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:20000'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            // Category must belong to the SAME store (tenant-safe existence check).
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('store_id', $storeId)],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}

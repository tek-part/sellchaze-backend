<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorefrontProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = app(CurrentStore::class)->id();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:120', Rule::unique('products', 'sku')->where('store_id', $storeId)->ignore($this->route('product'))],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'compare_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('store_id', $storeId)],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}

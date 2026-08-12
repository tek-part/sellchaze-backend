<?php

namespace App\Http\Requests;

use App\Models\Store;
use App\Services\StoreService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a Store. Ownership is enforced by StorePolicy
 * in the controller. Slug uniqueness ignores the current store.
 */
class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $store = $this->route('store'); // bound Store model

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', Rule::notIn(StoreService::RESERVED_SLUGS), Rule::unique('stores', 'slug')->ignore($store)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'default_locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'supported_locales' => ['sometimes', 'array', 'min:1'],
            'supported_locales.*' => [Rule::in(['ar', 'en'])],
            'supported_currencies' => ['sometimes', 'array', 'min:1', 'max:12'],
            'supported_currencies.*' => ['string', 'size:3', 'distinct'],
            'timezone' => ['sometimes', 'timezone'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_prices_include' => ['sometimes', 'boolean'],
            'shipping_enabled' => ['sometimes', 'boolean'],
            'shipping_flat_rate' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'shipping_free_over' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'status' => ['sometimes', 'nullable', Rule::in(Store::STATUSES)],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'banner' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}

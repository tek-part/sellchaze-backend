<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Owner create/update of a product variant. SKU is unique per store (nullable).
 * Gated by ScopeToStore (store ownership); product ownership is asserted in the
 * controller.
 */
class StorefrontProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $storeId = app(CurrentStore::class)->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:120', Rule::unique('store_product_variants', 'sku')->where('store_id', $storeId)->ignore($this->route('variant'))],
            'barcode' => ['nullable', 'string', 'max:120'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'options' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

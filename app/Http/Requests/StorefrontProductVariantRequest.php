<?php

namespace App\Http\Requests;

use App\Support\Localization\TranslationRules;
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
            'price_override' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'compare_price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:999999.999', 'decimal:0,3'],
            'options' => ['nullable', 'array', 'max:10', function ($attribute, $value, $fail) {
                $names = [];
                foreach (array_keys(is_array($value) ? $value : []) as $key) {
                    if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 80) {
                        $fail('Option names must be non-empty text of at most 80 characters.');
                    }
                    $normalized = mb_strtolower(trim((string) $key));
                    if (isset($names[$normalized])) {
                        $fail('Option names must be distinct regardless of casing or surrounding spaces.');
                    }
                    $names[$normalized] = true;
                }
            }],
            'options.*' => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ] + TranslationRules::for(['name']);
    }
}

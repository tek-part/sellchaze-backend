<?php

namespace App\Http\Requests;

use App\Models\StoreCollection;
use App\Support\Catalog\CollectionRules;
use App\Support\Localization\TranslationRules;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create (POST) and update (PUT/POST _method) payload for a merchant collection. */
class StoreCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the store scope + store.catalog.permission middleware
    }

    protected function prepareForValidation(): void
    {
        TranslationRules::decodeRequest($this);
        CollectionRules::decodeRequest($this);
        foreach (['published', 'remove_image'] as $flag) {
            if (is_string($this->input($flag))) {
                $this->merge([$flag => filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->input($flag)]);
            }
        }
        if (is_string($this->input('handle'))) {
            $this->merge(['handle' => strtolower(trim($this->input('handle')))]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST') && $this->route('collection') === null;
        $sometimes = $creating ? [] : ['sometimes'];
        $id = $this->route('collection');
        $storeId = app(CurrentStore::class)->id();

        return [
            'title' => [...$sometimes, 'required', 'string', 'max:255'],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('store_collections', 'slug')->where('store_id', $storeId)->ignore(is_numeric($id) ? (int) $id : null)],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'type' => [...$sometimes, 'required', 'in:manual,automated'],
            'published' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'in:'.implode(',', StoreCollection::SORT_ORDERS)],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
            'product_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'distinct'],
        ] + CollectionRules::validationRules($creating && $this->input('type') === 'automated')
            + TranslationRules::for(['name', 'description'], null, ['name' => 255, 'description' => 20000]);
    }

    public function messages(): array
    {
        return [
            'handle.unique' => 'Another collection in this store already uses this handle.',
            'handle.regex' => 'Use lowercase letters, numbers and single hyphens only.',
            'rules.required' => 'Add at least one condition to an automated collection.',
            'rules.max' => 'Use at most '.CollectionRules::MAX_RULES.' conditions.',
            'type.in' => 'Choose a manual or automated collection.',
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => CollectionRules::validateRows($validator, $this->input('rules'))];
    }
}

<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorefrontProductBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route permission and a policy check for every locked product.
    }

    public function rules(): array
    {
        return [
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*' => ['required', 'array:id,version'],
            'products.*.id' => ['required', 'integer', 'distinct'],
            'products.*.version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'changes' => ['required', 'array:price,is_active,category_id,add_category_id,remove_category_id', 'min:1'],
            'changes.price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'changes.is_active' => ['sometimes', 'required', 'boolean'],
            'changes.category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('store_id', app(CurrentStore::class)->id())],
            // Membership edits keep every other category; they cannot be combined with replacing the primary category.
            'changes.add_category_id' => ['sometimes', 'required', 'integer', 'prohibits:changes.category_id', 'different:changes.remove_category_id', Rule::exists('categories', 'id')->where('store_id', app(CurrentStore::class)->id())],
            'changes.remove_category_id' => ['sometimes', 'required', 'integer', 'prohibits:changes.category_id', Rule::exists('categories', 'id')->where('store_id', app(CurrentStore::class)->id())],
        ];
    }
}

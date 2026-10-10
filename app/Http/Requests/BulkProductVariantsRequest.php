<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkProductVariantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variants' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'variants.*' => ['required', 'array:id,version'],
            'variants.*.id' => ['required', 'integer', 'distinct'],
            'variants.*.version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'changes' => ['required', 'array:price_override,compare_price,cost,weight,is_active,image_media_id', 'min:1'],
            'changes.price_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'changes.compare_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'changes.cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'changes.weight' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999', 'decimal:0,3'],
            'changes.is_active' => ['sometimes', 'required', 'boolean'],
            'changes.image_media_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}

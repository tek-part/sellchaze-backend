<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkVariantSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variants' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'variants.*' => ['required', 'array:id,version,expected_stock,expected_reserved,expected_tracking'],
            'variants.*.id' => ['required', 'integer', 'distinct'],
            'variants.*.version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'variants.*.expected_stock' => ['required', 'integer', 'min:0', 'max:100000000'],
            'variants.*.expected_reserved' => ['required', 'integer', 'min:0', 'max:100000000'],
            'variants.*.expected_tracking' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}

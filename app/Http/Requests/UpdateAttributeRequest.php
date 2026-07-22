<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'attribute_values' => ['nullable', 'array'],
            'attribute_values.*.value' => ['required', 'string', 'max:255'],
        ];
    }
}

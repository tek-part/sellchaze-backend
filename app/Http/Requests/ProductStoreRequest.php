<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category' => ['required', 'integer', 'exists:categories,id'],
            'image' => ['nullable', 'image', 'mimes:jpg,png,webp', 'max:2048'],
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['nullable', 'integer', 'exists:attributes,id'],
        ];
    }
}

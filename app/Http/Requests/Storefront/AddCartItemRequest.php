<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];
    }
}

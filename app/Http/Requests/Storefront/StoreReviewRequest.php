<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The complete option list of one product in its new order; each entry carries its editorial version. */
class ReorderProductVariantsRequest extends FormRequest
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
        ];
    }
}

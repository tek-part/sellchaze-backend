<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 6H: merchant standalone internal note on an order.
 */
class AddOrderNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership enforced by store.scope
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:2000'],
        ];
    }
}

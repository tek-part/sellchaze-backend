<?php

namespace App\Http\Requests;

use App\Models\FinancingRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1', 'max:999999999999'],
            'currency' => ['nullable', 'string', 'max:8'],
            'purpose' => ['required', Rule::in(FinancingRequest::PURPOSES)],
            'repayment_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'has_confirmed_order' => ['required', 'boolean'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\InvestmentOpportunity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvestmentOpportunityRequest extends FormRequest
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
            'kind' => ['required', Rule::in(InvestmentOpportunity::KINDS)],
            'title' => ['required', 'string', 'min:5', 'max:191'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'amount_sought' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => ['nullable', 'string', 'max:8'],
            'equity_offered' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}

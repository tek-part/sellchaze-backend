<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    protected function prepareForValidation(): void
    {
        $user = Auth::user();
        if ($user->hasRole('Merchant') && ! $user->hasRole('Admin')) {
            $this->merge(['invited_role' => 'supplier']);
        } elseif ($user->hasRole('Supplier') && ! $user->hasRole('Admin')) {
            $this->merge(['invited_role' => 'merchant']);
        }
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                Rule::unique('invitations', 'email')->where(function ($query) {
                    return $query->where('sender_user_id', Auth::id())
                        ->whereNull('registered_at');
                }),
            ],
            'invited_role' => ['required', 'in:merchant,supplier'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = Auth::user();
            $role = $this->input('invited_role');

            if ($user->hasRole('Merchant') && ! $user->hasRole('Admin') && $role !== 'supplier') {
                $validator->errors()->add('invited_role', __('Merchants can only invite suppliers.'));
            }
            if ($user->hasRole('Supplier') && ! $user->hasRole('Admin') && $role !== 'merchant') {
                $validator->errors()->add('invited_role', __('Suppliers can only invite merchants.'));
            }
        });
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('You already have a pending invitation for this email.'),
        ];
    }
}

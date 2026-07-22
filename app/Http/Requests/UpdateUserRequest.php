<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('user') ?? $this->route('id');
        $id = is_object($id) ? $id->id : (int) $id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($id)],
            'password' => ['nullable', 'same:confirm-password', 'min:6'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }
}

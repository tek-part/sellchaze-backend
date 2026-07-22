<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'account_type' => ['required', 'in:merchant,supplier'],
            'name' => ['required', 'string', 'min:5', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:255', 'unique:profiles,username'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(10)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'password_confirmation' => ['required', 'min:10'],
            'biography' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpg,png,webp', 'max:2048'],
            'company' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string'],
            'city' => ['nullable', 'string', 'min:5', 'max:255'],
            'address' => ['nullable', 'string', 'max:255', 'regex:/(^[-0-9A-Za-z.,\/ ]+$)/'],
            'gender' => ['required', 'in:male,female'],
            'phone' => ['nullable', 'numeric', 'min:11'],
            'whatsapp' => ['nullable', 'numeric', 'min:11'],
            'birthdate' => ['nullable', 'date_format:Y-m-d', 'before:13 years ago'],
            'social_media' => ['nullable', 'array'],
            'products' => ['nullable', 'array'],
            'active' => ['nullable', 'boolean'],
            'private' => ['nullable', 'boolean'],
        ];

        return $rules;
    }
}

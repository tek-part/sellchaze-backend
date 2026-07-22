<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class StoreStaffUserRequest extends FormRequest
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
        $assignable = Role::query()->where('name', '!=', 'Supplier')->pluck('name')->all();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::in($assignable)],
            'profile' => ['nullable', 'array'],
            'profile.username' => ['nullable', 'string', 'max:255', 'unique:profiles,username'],
            'profile.biography' => ['nullable', 'string'],
            'profile.photo' => ['nullable', 'string', 'max:255'],
            'profile.company' => ['nullable', 'string', 'max:255'],
            'profile.country' => ['nullable', 'string', 'max:255'],
            'profile.city' => ['nullable', 'string', 'max:255'],
            'profile.address' => ['nullable', 'string'],
            'profile.gender' => ['nullable', 'string', 'max:32'],
            'profile.phone' => ['nullable', 'string', 'max:255'],
            'profile.whatsapp' => ['nullable', 'string', 'max:255'],
            'profile.birthdate' => ['nullable', 'date'],
            'profile.social_media' => ['nullable', 'string'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAuthProfileRequest extends FormRequest
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
        $user = $this->user();
        $profileId = $user?->profile?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'notify_login_email' => ['sometimes', 'boolean'],
            'profile' => ['nullable', 'array'],
            'profile.username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('profiles', 'username')->ignore($profileId),
            ],
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
            'profile.cover_photo' => ['nullable', 'string', 'max:255'],
            'profile.website' => ['nullable', 'string', 'max:255'],
            'profile.tagline' => ['nullable', 'string', 'max:191'],
            'profile.is_public' => ['sometimes', 'boolean'],
        ];
    }
}

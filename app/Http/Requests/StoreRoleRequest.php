<?php

namespace App\Http\Requests;

use App\Services\PermissionGroupBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
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
        $guard = (string) config('auth.defaults.guard', 'web');
        $valid = PermissionGroupBuilder::validPermissionNames();

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', $guard)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($valid)],
        ];
    }
}

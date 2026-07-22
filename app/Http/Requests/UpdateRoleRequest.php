<?php

namespace App\Http\Requests;

use App\Services\PermissionGroupBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UpdateRoleRequest extends FormRequest
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
        /** @var Role $role */
        $role = $this->route('role');
        $valid = PermissionGroupBuilder::validPermissionNames();

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->where('guard_name', $guard)->ignore($role->id),
            ],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($valid)],
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Employees = child users with parent_user_id pointing to the owner (Merchant/Supplier).
 * Each employee has their own login and the Spatie "Employee" role.
 */
class EmployeesApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $owner = $request->user();
        if ($owner->hasRole('Admin')) {
            $rows = User::query()->role('Employee')->orderBy('name')->get();
        } else {
            $rows = User::query()->where('parent_user_id', $owner->id)->orderBy('name')->get();
        }

        return response()->json([
            'data' => $rows->map(fn (User $u) => $this->serialize($u))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $employee = new User;
        $employee->name = $validated['name'];
        $employee->email = $validated['email'];
        $employee->password = Hash::make($validated['password']);
        $employee->is_active = true;
        $employee->parent_user_id = (int) $owner->id;
        $employee->save();
        $employee->syncRoles(['Employee']);

        return response()->json(['data' => $this->serialize($employee)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $owner = $request->user();
        $employee = User::query()->findOrFail($id);
        $this->authorizeOwnership($owner, $employee);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'email' => ['sometimes', 'email', 'max:191', Rule::unique('users', 'email')->ignore($employee->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:6'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) $employee->name = $validated['name'];
        if (array_key_exists('email', $validated)) $employee->email = $validated['email'];
        if (! empty($validated['password'])) $employee->password = Hash::make($validated['password']);
        if (array_key_exists('is_active', $validated)) $employee->is_active = (bool) $validated['is_active'];
        $employee->save();

        return response()->json(['data' => $this->serialize($employee)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $owner = $request->user();
        $employee = User::query()->findOrFail($id);
        $this->authorizeOwnership($owner, $employee);
        $employee->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function authorizeOwnership(User $owner, User $employee): void
    {
        if ($owner->hasRole('Admin')) return;
        if ((int) $employee->parent_user_id !== (int) $owner->id) {
            abort(403, 'Not your employee.');
        }
    }

    private function serialize(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'is_active' => (bool) ($u->is_active ?? true),
            'parent_user_id' => $u->parent_user_id,
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}

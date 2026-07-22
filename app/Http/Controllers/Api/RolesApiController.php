<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Services\PermissionGroupBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesApiController extends Controller
{
    private const PROTECTED_ROLE_NAMES = ['Admin'];

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'users_count' => (int) $r->users_count,
                'permissions' => $r->permissions->pluck('name')->values()->all(),
            ]);

        $permissions = Permission::query()->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'roles' => $roles,
            'permissions' => $permissions,
            'permission_groups' => PermissionGroupBuilder::buildGroups(),
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $permissions = $validated['permissions'] ?? [];
        unset($validated['permissions']);

        $guard = (string) config('auth.defaults.guard', 'web');
        $role = Role::query()->create([
            'name' => $validated['name'],
            'guard_name' => $guard,
        ]);

        if ($permissions !== []) {
            $role->syncPermissions($permissions);
        }

        $this->forgetPermissionCache();

        $role->load('permissions:id,name');

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ],
        ], 201);
    }

    public function usersForRole(Role $role): JsonResponse
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users */
        $users = $role->users()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active']);

        return response()->json([
            'data' => $users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => (bool) $u->is_active,
            ])->values()->all(),
        ]);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions:id,name');

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ],
            'permission_groups' => PermissionGroupBuilder::buildGroups(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        if (in_array($role->name, self::PROTECTED_ROLE_NAMES, true)
            && $request->filled('name')
            && $request->string('name')->toString() !== $role->name) {
            return response()->json(['message' => __('The Admin role cannot be renamed.')], 422);
        }

        $validated = $request->validated();
        $permissions = $validated['permissions'] ?? null;
        unset($validated['permissions']);

        if (array_key_exists('name', $validated) && $validated['name'] !== '') {
            $role->name = $validated['name'];
            $role->save();
        }

        if (is_array($permissions)) {
            $role->syncPermissions($permissions);
        }

        $this->forgetPermissionCache();

        $role->load('permissions:id,name');

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ],
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, self::PROTECTED_ROLE_NAMES, true)) {
            return response()->json(['message' => __('This role cannot be deleted.')], 422);
        }

        $pivotTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $assigned = (int) DB::table($pivotTable)->where('role_id', $role->id)->count();
        if ($assigned > 0) {
            return response()->json([
                'message' => __('Cannot delete a role that is assigned to users.'),
            ], 422);
        }

        $role->delete();
        $this->forgetPermissionCache();

        return response()->json(['message' => 'OK']);
    }

    private function forgetPermissionCache(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}

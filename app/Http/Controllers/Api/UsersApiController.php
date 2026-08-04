<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffUserRequest;
use App\Http\Requests\UpdateStaffUserRequest;
use App\Http\Requests\UpdateStaffUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationChannelResolver;
use App\Services\UserProfileSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UsersApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pendingOnly = $request->boolean('pending_registration');
        if ($pendingOnly) {
            abort_unless($request->user()->can('users-pending-list'), 403);
        } else {
            abort_unless($request->user()->can('users-list'), 403);
        }

        $query = User::with(['profile', 'roles'])->orderByDesc('id');
        $query->where(function ($q) {
            $q->where('email', 'not like', 'guest_%@wigpleasure.com')
                ->orWhereHas('roles');
        });

        if ($pendingOnly) {
            $query->where('pending_approval', true);
            $query->whereRaw('LOWER(TRIM(COALESCE(registration_role, ""))) NOT IN (?, ?)', ['supplier', 'merchant']);
        } else {
            $query->whereDoesntHave('roles', function ($q) {
                $q->whereIn('name', ['Supplier', 'Merchant']);
            });
            $query->whereNot(function ($q) {
                $q->where('pending_approval', true)
                    ->whereRaw('LOWER(TRIM(COALESCE(registration_role, ""))) IN (?, ?)', ['supplier', 'merchant']);
            });
        }

        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('users.created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('users.created_at', '<=', $request->date('date_to'));
        }

        $perPage = min((int) $request->get('per_page', 20), 100);

        return UserResource::collection($query->paginate($perPage))->response();
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $user->loadMissing('roles');
        $this->ensureStaffUser($user);
        $this->authorizeStaffDirectoryView($request, $user);
        $user->load(['profile', 'roles']);

        return (new UserResource($user))->response();
    }

    public function store(StoreStaffUserRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('users-list'), 403);

        $validated = $request->validated();
        $roles = $validated['roles'] ?? [];
        $profile = $validated['profile'] ?? null;

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => $validated['is_active'] ?? true,
        ]);
        $user->syncRoles($roles);

        if ($profile !== null) {
            UserProfileSync::sync($user, $profile);
        } else {
            $user->profile()->create([
                'username' => 'user_'.$user->id,
                'gender' => 'male',
            ]);
        }

        $user->load(['profile', 'roles']);
        ActivityLogger::log('staff_user.created', $user, ['email' => $user->email]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateStaffUserRequest $request, User $user): JsonResponse
    {
        $user->loadMissing('roles');
        $this->ensureStaffUser($user);
        $this->authorizeStaffDirectoryMutate($request, $user, $request->boolean('approve_pending_registration'));

        $validated = $request->validated();
        if ($request->boolean('approve_pending_registration')) {
            $approvalRole = $request->input('approval_role');
            unset($validated['approve_pending_registration']);
            if (array_key_exists('approval_role', $validated)) {
                unset($validated['approval_role']);
            }
            $this->approvePendingRegistration($user, is_string($approvalRole) ? $approvalRole : null);
            $user->refresh();
        }

        $roles = $validated['roles'] ?? null;
        $profile = $validated['profile'] ?? null;
        unset($validated['roles'], $validated['profile']);
        if (array_key_exists('approve_pending_registration', $validated)) {
            unset($validated['approve_pending_registration']);
        }
        if (array_key_exists('approval_role', $validated)) {
            unset($validated['approval_role']);
        }

        if (array_key_exists('password', $validated)) {
            if ($validated['password'] === null || $validated['password'] === '') {
                unset($validated['password']);
            } else {
                $validated['password'] = Hash::make($validated['password']);
            }
        }

        $before = ['name' => $user->name, 'email' => $user->email, 'is_active' => $user->is_active];
        $user->update($validated);

        if (is_array($roles)) {
            $user->syncRoles($roles);
        }

        if ($profile !== null) {
            $user->refresh();
            UserProfileSync::sync($user, $profile);
        }

        $user->load(['profile', 'roles']);
        ActivityLogger::log('staff_user.updated', $user, ['before' => $before]);

        return (new UserResource($user))->response();
    }

    public function updateStatus(UpdateStaffUserStatusRequest $request, User $user): JsonResponse
    {
        $user->loadMissing('roles');
        $this->ensureStaffUser($user);
        $this->authorizeStaffDirectoryMutate($request, $user, false);
        $actor = $request->user();
        if ($actor && $actor->id === $user->id) {
            return response()->json(['message' => __('You cannot change your own active status.')], 422);
        }

        $user->is_active = $request->boolean('is_active');
        $user->save();

        ActivityLogger::log('staff_user.status_changed', $user, ['is_active' => $user->is_active]);

        $user->load(['profile', 'roles']);

        return (new UserResource($user))->response();
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $user->loadMissing('roles');
        $this->ensureStaffUser($user);
        $this->authorizeStaffDirectoryMutate($request, $user, false);
        $actor = $request->user();
        if ($actor && $actor->id === $user->id) {
            return response()->json(['message' => __('You cannot delete your own account.')], 422);
        }

        ActivityLogger::log('staff_user.deleted', $user, ['email' => $user->email]);
        $user->delete();

        return response()->json(['message' => 'OK']);
    }

    public function notificationPreferences(Request $request, User $user): JsonResponse
    {
        $user->loadMissing('roles');
        $this->ensureStaffUser($user);
        if ($user->pending_approval) {
            abort(403);
        }
        $this->authorizeStaffDirectoryView($request, $user);
        $resolver = app(NotificationChannelResolver::class);

        return response()->json([
            'data' => [
                'preferences' => $resolver->preferencesPayloadForUser($user),
            ],
        ]);
    }

    public function updateNotificationPreferences(Request $request, User $user): JsonResponse
    {
        $user->loadMissing('roles');
        $this->ensureStaffUser($user);
        if ($user->pending_approval) {
            abort(403);
        }
        $this->authorizeStaffDirectoryView($request, $user);
        $resolver = app(NotificationChannelResolver::class);
        $allowed = $resolver->applicableKeysForUser($user);

        $data = $request->validate([
            'preferences' => ['required', 'array'],
        ]);
        $incoming = $data['preferences'];

        foreach ($incoming as $key => $vals) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    'preferences' => [__('Invalid notification preference key.')],
                ]);
            }
            if (! is_array($vals)) {
                throw ValidationException::withMessages([
                    'preferences' => [__('Each preference must be an object.')],
                ]);
            }
            Validator::make($vals, [
                'in_app' => ['sometimes', 'boolean'],
                'email' => ['sometimes', 'boolean'],
            ])->validate();
        }

        $user->notification_preferences = $resolver->mergeOverrides($user, $incoming);
        $resolver->syncNotifyLoginEmailFromPreferences($user);
        $user->save();

        ActivityLogger::log('staff_user.notification_preferences_updated', $user);

        $user->load(['profile', 'roles']);

        return response()->json([
            'data' => [
                'preferences' => $resolver->preferencesPayloadForUser($user),
            ],
            'user' => new UserResource($user),
        ]);
    }

    private function approvePendingRegistration(User $user, ?string $approvalRoleFromAdmin = null): void
    {
        if (! $user->pending_approval) {
            throw ValidationException::withMessages([
                'approve_pending_registration' => [__('This user is not waiting for approval.')],
            ]);
        }

        $role = $this->normalizeStaffOrSupplierRole($approvalRoleFromAdmin)
            ?? $this->normalizeStaffOrSupplierRole($user->registration_role);
        if ($role === null) {
            throw ValidationException::withMessages([
                'approve_pending_registration' => [__('No valid registration role is set for this user. Choose Staff, Supplier, or Merchant when approving.')],
            ]);
        }

        // Same path self-service sign-up uses, so approval can never drift from it.
        app(\App\Services\Users\RegistrationApprover::class)
            ->approve($user, $role, 'staff_user.registration_approved');
    }

    private function normalizeStaffOrSupplierRole(?string $role): ?string
    {
        if ($role === null || trim($role) === '') {
            return null;
        }

        return match (strtolower(trim($role))) {
            'staff' => 'Staff',
            'supplier' => 'Supplier',
            'merchant' => 'Merchant',
            default => null,
        };
    }

    private function ensureStaffUser(User $user): void
    {
        // Pending sign-ups (staff or supplier) must be reachable for approval PATCH, even if a Supplier role was assigned early.
        if ($user->pending_approval) {
            return;
        }

        if ($user->roles->count() === 0) {
            return;
        }

        if ($user->hasRole('Supplier') && ! $user->hasAnyRole(['Staff', 'Manager', 'Admin', 'Merchant'])) {
            abort(404);
        }
    }

    private function isPendingNonSupplierStaffSignup(User $user): bool
    {
        if (! $user->pending_approval) {
            return false;
        }

        return strtolower(trim((string) ($user->registration_role ?? ''))) !== 'supplier';
    }

    private function authorizeStaffDirectoryView(Request $request, User $user): void
    {
        if ($this->isPendingNonSupplierStaffSignup($user)) {
            abort_unless($request->user()->can('users-pending-list'), 403);
        } else {
            abort_unless($request->user()->can('users-list'), 403);
        }
    }

    private function authorizeStaffDirectoryMutate(Request $request, User $user, bool $approvingRegistration): void
    {
        if ($approvingRegistration) {
            abort_unless($request->user()->can('users-pending-list'), 403);
            if (! $this->isPendingNonSupplierStaffSignup($user)) {
                abort_unless($request->user()->can('users-list'), 403);
            }

            return;
        }

        if ($this->isPendingNonSupplierStaffSignup($user)) {
            abort_unless($request->user()->can('users-pending-list'), 403);
        } else {
            abort_unless($request->user()->can('users-list'), 403);
        }
    }

    /**
     * Role names allowed when creating/editing staff users (SPA picker; avoids exposing full /admin/roles).
     */
    public function staffRoleOptions(Request $request): JsonResponse
    {
        $guard = config('auth.defaults.guard', 'web');
        $omit = ['Admin', 'Supplier', 'Merchant', 'Customer'];
        $rows = Role::query()
            ->where('guard_name', $guard)
            ->whereNotIn('name', $omit)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $rows->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name])->values()->all(),
        ]);
    }
}

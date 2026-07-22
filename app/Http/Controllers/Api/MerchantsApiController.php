<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Rbac\UserScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Admin/Staff/Manager view of Merchant accounts (and pending merchant sign-ups).
 * Mirrors the admin branch of SuppliersApiController, scoped to the Merchant role.
 */
class MerchantsApiController extends Controller
{
    private function baseQuery()
    {
        return User::query()->where(function ($w) {
            $w->whereHas('roles', fn ($r) => $r->where('name', 'Merchant'))
                ->orWhere(function ($p) {
                    $p->where('pending_approval', true)
                        ->whereRaw('LOWER(TRIM(COALESCE(registration_role, ""))) = ?', ['merchant']);
                });
        });
    }

    private function resolveMerchant(int $id): User
    {
        $user = $this->baseQuery()->where('users.id', $id)->first();
        if (! $user) {
            abort(404);
        }

        return $user;
    }

    private function authorizeDirectory(?User $user): void
    {
        if (! UserScope::seesAdminSupplierDirectory($user)) {
            abort(403);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeDirectory($request->user());

        $perPage = min((int) $request->get('per_page', 30), 100);
        $q = $this->baseQuery()->orderByDesc('pending_approval')->orderBy('name');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $q->where(function ($qq) use ($term) {
                $qq->where('name', 'like', $term)->orWhere('email', 'like', $term);
            });
        }
        if ($request->filled('date_from')) {
            $q->whereDate('users.created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('users.created_at', '<=', $request->date('date_to'));
        }

        $paginated = $q->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'username' => optional($m->profile)->username,
                'pending_approval' => (bool) $m->pending_approval,
                'is_active' => (bool) $m->is_active,
                'registration_role' => $m->registration_role,
            ])->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeDirectory($request->user());
        $m = $this->resolveMerchant($id);

        return response()->json([
            'data' => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'phone' => $m->phone,
                'last_login_at' => $m->last_login_at,
                'created_at' => $m->created_at?->toIso8601String(),
                'updated_at' => $m->updated_at?->toIso8601String(),
                'pending_approval' => (bool) $m->pending_approval,
                'is_active' => (bool) $m->is_active,
                'registration_role' => $m->registration_role,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! UserScope::isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $m = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);
        $m->assignRole('Merchant');

        // One Merchant = one Store: provision the store as part of account creation.
        app(\App\Services\Stores\StoreProvisioner::class)->ensureFor($m);

        ActivityLogger::log('merchant.created', $m, ['email' => $m->email]);

        return response()->json([
            'data' => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! UserScope::isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $m = $this->resolveMerchant($id);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$m->id],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        $m->name = $validated['name'];
        $m->email = $validated['email'];
        if (! empty($validated['password'])) {
            $m->password = Hash::make($validated['password']);
        }
        $m->save();

        return response()->json([
            'data' => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email],
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (! UserScope::isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $m = $this->resolveMerchant($id);
        if ($m->pending_approval) {
            return response()->json(['message' => __('Activate or deactivate only after the account is approved.')], 422);
        }

        $request->validate(['is_active' => ['required', 'boolean']]);
        $actor = $request->user();
        if ($actor && $actor->id === $m->id) {
            return response()->json(['message' => __('You cannot change your own active status.')], 422);
        }

        $m->is_active = $request->boolean('is_active');
        $m->save();
        ActivityLogger::log('merchant.status_changed', $m, ['is_active' => $m->is_active]);

        return response()->json(['data' => ['id' => $m->id, 'is_active' => (bool) $m->is_active]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! UserScope::isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $m = $this->resolveMerchant($id);
        $actor = $request->user();
        if ($actor && $actor->id === $m->id) {
            return response()->json(['message' => __('You cannot delete your own account.')], 422);
        }

        ActivityLogger::log('merchant.deleted', $m, ['email' => $m->email]);
        $m->delete();

        return response()->json(['message' => 'OK']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        if (! UserScope::isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $actor = $request->user();
        $ids = collect($validated['ids'])->unique()->values();
        foreach ($ids as $i) {
            if ($actor && (int) $i === (int) $actor->id) {
                return response()->json(['message' => __('You cannot delete your own account.')], 422);
            }
        }

        $users = $this->baseQuery()->whereIn('users.id', $ids->all())->get();
        foreach ($users as $u) {
            ActivityLogger::log('merchant.deleted', $u, ['email' => $u->email, 'bulk' => true]);
            $u->delete();
        }

        return response()->json(['message' => 'OK', 'deleted' => $users->count()]);
    }
}

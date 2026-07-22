<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantWigpleasureCategorySupplier;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Rbac\UserScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SuppliersApiController extends Controller
{
    /**
     * Users who appear on the admin suppliers list: Supplier role, or pending self-registration as supplier.
     */
    private function adminSuppliersBaseQuery()
    {
        return User::query()->where(function ($w) {
            $w->whereHas('roles', fn ($r) => $r->where('name', 'Supplier'))
                ->orWhere(function ($p) {
                    $p->where('pending_approval', true)
                        ->whereRaw('LOWER(TRIM(COALESCE(registration_role, ""))) = ?', ['supplier']);
                });
        });
    }

    private function resolveAdminSupplier(int $supplierId): User
    {
        $user = $this->adminSuppliersBaseQuery()->where('users.id', $supplierId)->first();
        if (! $user) {
            abort(404);
        }

        return $user;
    }

    /**
     * Admin / Staff / Manager: supplier accounts + pending supplier sign-ups. Merchant: accepted partner suppliers only.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min((int) $request->get('per_page', 30), 100);

        if (UserScope::seesAdminSupplierDirectory($user)) {
            $q = $this->adminSuppliersBaseQuery()->orderByDesc('pending_approval')->orderBy('name');
            if ($request->filled('search')) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
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
                'data' => $paginated->getCollection()->map(fn (User $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'email' => $s->email,
                    'username' => optional($s->profile)->username,
                    'pending_approval' => (bool) $s->pending_approval,
                    'is_active' => (bool) $s->is_active,
                    'registration_role' => $s->registration_role,
                ])->values(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ]);
        }

        if ($user->hasRole('Merchant')) {
            $partners = $user->suppliersAsMerchant()
                ->wherePivot('status', 'accepted')
                ->orderBy('name')
                ->get();

            $list = $partners;
            if ($request->filled('search')) {
                $term = strtolower($request->string('search')->trim());
                $list = $partners->filter(function (User $s) use ($term) {
                    return str_contains(strtolower($s->name), $term)
                        || str_contains(strtolower((string) $s->email), $term);
                })->values();
            }

            $page = max((int) $request->get('page', 1), 1);
            $total = $list->count();
            $slice = $list->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'data' => $slice->map(fn (User $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'email' => $s->email,
                    'username' => optional($s->profile)->username,
                    'pending_approval' => false,
                    'is_active' => (bool) $s->is_active,
                    'registration_role' => null,
                    'partnership_status' => $s->pivot->status,
                ])->values(),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max((int) ceil($total / $perPage), 1),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ]);
        }

        return response()->json(['data' => []]);
    }

    public function routingCategories(Request $request, int $supplierId): JsonResponse
    {
        $user = $request->user();
        $merchantId = UserScope::effectiveMerchantUserId($user);
        if (! $merchantId) {
            return response()->json(['category_ids' => []], 200);
        }
        if (! $this->routingTargetSupplierExists($supplierId)) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $ids = MerchantWigpleasureCategorySupplier::query()
            ->where('merchant_id', $merchantId)
            ->where('supplier_user_id', $supplierId)
            ->orderBy('wigpleasure_category_id')
            ->pluck('wigpleasure_category_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return response()->json(['category_ids' => $ids], 200);
    }

    public function saveRoutingCategories(Request $request, int $supplierId): JsonResponse
    {
        $validated = $request->validate([
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'min:1'],
        ]);

        $user = $request->user();
        $merchantId = UserScope::effectiveMerchantUserId($user);
        if (! $merchantId) {
            return response()->json(['message' => 'Merchant context is required.'], 422);
        }
        if (! $this->routingTargetSupplierExists($supplierId)) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $categoryIds = collect($validated['category_ids'] ?? [])->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values();

        MerchantWigpleasureCategorySupplier::query()
            ->where('merchant_id', $merchantId)
            ->where('supplier_user_id', $supplierId)
            ->whereNotIn('wigpleasure_category_id', $categoryIds->all())
            ->delete();

        foreach ($categoryIds as $categoryId) {
            MerchantWigpleasureCategorySupplier::firstOrCreate([
                'merchant_id' => $merchantId,
                'supplier_user_id' => $supplierId,
                'wigpleasure_category_id' => $categoryId,
            ]);
        }

        return response()->json(['success' => true, 'category_ids' => $categoryIds->all()], 200);
    }

    private function routingTargetSupplierExists(int $supplierId): bool
    {
        return User::role('Supplier')->where('id', $supplierId)->exists()
            || User::query()
                ->where('id', $supplierId)
                ->where('pending_approval', true)
                ->whereRaw('LOWER(TRIM(COALESCE(registration_role, ""))) = ?', ['supplier'])
                ->exists();
    }

    public function show(Request $request, int $supplierId): JsonResponse
    {
        $user = $request->user();
        $merchantId = UserScope::effectiveMerchantUserId($user);

        if (UserScope::seesAdminSupplierDirectory($user)) {
            $supplier = $this->resolveAdminSupplier($supplierId);
        } else {
            $supplier = User::role('Supplier')->findOrFail($supplierId);
            if (! $merchantId) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $partnershipStatus = null;
        if ($merchantId) {
            $pivot = User::find($merchantId)?->suppliersAsMerchant()
                ->where('users.id', $supplier->id)
                ->first()?->pivot;
            $partnershipStatus = $pivot?->status;
        }

        if (! UserScope::seesAdminSupplierDirectory($user)) {
            if (! $merchantId) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        return response()->json([
            'data' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'last_login_at' => $supplier->last_login_at,
                'created_at' => $supplier->created_at?->toIso8601String(),
                'updated_at' => $supplier->updated_at?->toIso8601String(),
                'partnership_status' => $partnershipStatus,
                'pending_approval' => (bool) $supplier->pending_approval,
                'is_active' => (bool) $supplier->is_active,
                'registration_role' => $supplier->registration_role,
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
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'min:1'],
        ]);

        $supplier = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);
        $supplier->assignRole('Supplier');

        // One Supplier = one Store: provision the store as part of account creation.
        app(\App\Services\Stores\StoreProvisioner::class)->ensureFor($supplier);

        return response()->json([
            'data' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'email' => $supplier->email,
            ],
        ], 201);
    }

    public function update(Request $request, int $supplierId): JsonResponse
    {
        if (! UserScope::isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $supplier = $this->resolveAdminSupplier($supplierId);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$supplier->id],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        $supplier->name = $validated['name'];
        $supplier->email = $validated['email'];
        if (! empty($validated['password'])) {
            $supplier->password = Hash::make($validated['password']);
        }
        $supplier->save();

        return response()->json([
            'data' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'email' => $supplier->email,
            ],
        ]);
    }

    public function updateStatus(Request $request, int $supplierId): JsonResponse
    {
        if (! UserScope::isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $supplier = $this->resolveAdminSupplier($supplierId);
        if ($supplier->pending_approval) {
            return response()->json(['message' => __('Activate or deactivate only after the account is approved.')], 422);
        }

        $request->validate(['is_active' => ['required', 'boolean']]);
        $actor = $request->user();
        if ($actor && $actor->id === $supplier->id) {
            return response()->json(['message' => __('You cannot change your own active status.')], 422);
        }

        $supplier->is_active = $request->boolean('is_active');
        $supplier->save();

        ActivityLogger::log('supplier.status_changed', $supplier, ['is_active' => $supplier->is_active]);

        return response()->json([
            'data' => [
                'id' => $supplier->id,
                'is_active' => (bool) $supplier->is_active,
            ],
        ]);
    }

    public function destroy(Request $request, int $supplierId): JsonResponse
    {
        if (! UserScope::isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $supplier = $this->resolveAdminSupplier($supplierId);
        $actor = $request->user();
        if ($actor && $actor->id === $supplier->id) {
            return response()->json(['message' => __('You cannot delete your own account.')], 422);
        }

        ActivityLogger::log('supplier.deleted', $supplier, ['email' => $supplier->email]);
        $supplier->delete();

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
        foreach ($ids as $id) {
            if ($actor && (int) $id === (int) $actor->id) {
                return response()->json(['message' => __('You cannot delete your own account.')], 422);
            }
        }

        $users = $this->adminSuppliersBaseQuery()->whereIn('users.id', $ids->all())->get();
        foreach ($users as $u) {
            ActivityLogger::log('supplier.deleted', $u, ['email' => $u->email, 'bulk' => true]);
            $u->delete();
        }

        return response()->json(['message' => 'OK', 'deleted' => $users->count()]);
    }
}

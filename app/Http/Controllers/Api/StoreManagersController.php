<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Services\Stores\StoreManagers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreManagersController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $actor = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($actor && (int) $store->owner_user_id === (int) $actor->id
            && ! $actor->parent_user_id && $actor->hasAnyRole(['Merchant']), 403);

        return $store;
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', 'in:all,active,inactive,removed'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = StoreManagers::query($store, ($filters['status'] ?? '') === 'removed')->with('permissions');
        if (in_array($filters['status'] ?? '', ['active', 'inactive'], true)) {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if (trim($filters['search'] ?? '') !== '') {
            $term = '%'.trim($filters['search']).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }
        $rows = $query->orderByDesc('id')->paginate(20);

        return $this->respond([
            'data' => $rows->getCollection()->map(fn (User $manager) => StoreManagers::serialize($manager))->values()->all(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
            'permissions' => collect(StoreManagers::PERMISSIONS)->map(fn ($labels, $key) => ['key' => $key, 'label' => $labels, 'required' => $key === 'store.view'])->values()->all(),
        ]);
    }

    private function data(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($id)],
            'password' => [$id === null ? 'required' : 'nullable', 'string', 'min:12', 'max:128'],
            'is_active' => [$id === null ? 'sometimes' : 'required', 'boolean'],
            'permissions' => ['present', 'array', 'max:10'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_keys(StoreManagers::PERMISSIONS)), Rule::exists('permissions', 'name')->where('guard_name', 'web')],
            'version' => [$id === null ? 'prohibited' : 'required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'roles' => ['prohibited'], 'parent_user_id' => ['prohibited'], 'store_id' => ['prohibited'],
        ]);
    }

    public function create(Request $request, StoreManagers $managers): JsonResponse
    {
        $store = $this->store($request);

        return $this->respond(['data' => $managers->create($store, $request->user(), $this->data($request))], 201);
    }

    public function update(Request $request, StoreManagers $managers): JsonResponse
    {
        $store = $this->store($request);
        $id = (int) $request->route('manager');
        StoreManagers::query($store)->findOrFail($id);

        return $this->respond(['data' => $managers->update($store, $request->user(), $id, $this->data($request, $id))]);
    }

    public function remove(Request $request, StoreManagers $managers): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['version' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']]);

        return $this->respond(['data' => $managers->remove($store, $request->user(), (int) $request->route('manager'), $data['version'])]);
    }

    public function restore(Request $request, StoreManagers $managers): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['version' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']]);

        return $this->respond(['data' => $managers->restore($store, $request->user(), (int) $request->route('manager'), $data['version'])]);
    }
}

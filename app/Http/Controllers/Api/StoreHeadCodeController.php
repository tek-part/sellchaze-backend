<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\StoreHeadCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreHeadCodeController extends Controller
{
    public function __construct(private readonly StoreHeadCode $code) {}

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $user->id === (int) $store->owner_user_id || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->code->configuration($this->store($request))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'html' => ['present', 'nullable', 'string', 'max:65535']]);
        $updated = DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $configuration = $this->code->configuration($locked);
            abort_unless($configuration['version'] === (int) $data['version'], 409, 'Head code changed. Reload before saving.');
            $locked->update(['head_code_configuration' => array_replace($locked->head_code_configuration ?? [], ['version' => $configuration['version'] + 1, 'html' => $data['html'] ?? ''])]);

            return $locked;
        });
        $request->attributes->set('store', $updated);

        return $this->index($request);
    }
}

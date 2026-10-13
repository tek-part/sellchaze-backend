<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\MerchantDashboardGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreDashboardGuideController extends Controller
{
    public function __construct(private readonly MerchantDashboardGuide $guide) {}

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $user->id === (int) $store->owner_user_id || $user->can('stores-edit') || $user->can('store.settings.manage')), 403);

        return $store;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->guide->state($this->store($request))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'completed' => ['present', 'array:'.implode(',', MerchantDashboardGuide::KEYS)],
            'completed.*' => ['boolean'], 'hidden_panels' => ['present', 'array', 'max:3'],
            'hidden_panels.*' => ['string', 'distinct', Rule::in(['setup', 'help', 'app'])]]);
        DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $current = $this->guide->preferences($locked);
            abort_unless((int) $data['version'] === (int) $current['version'], 409, 'Dashboard preferences changed. Reload before saving.');
            $locked->update(['dashboard_preferences' => ['version' => $current['version'] + 1,
                'completed' => array_map(fn ($value) => (bool) $value, $data['completed']), 'hidden_panels' => $data['hidden_panels']]]);
        });
        $request->attributes->set('store', $store->fresh());

        return $this->index($request);
    }
}

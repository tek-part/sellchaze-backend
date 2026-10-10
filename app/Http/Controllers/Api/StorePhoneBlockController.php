<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePhoneBlock;
use App\Models\StorePhoneBlockEvent;
use App\Services\Commerce\OrderLimits;
use App\Services\Commerce\PhoneBlocking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorePhoneBlockController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.orders.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, OrderLimits $limits): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:64'], 'status' => ['nullable', 'in:active,inactive,all'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = StorePhoneBlock::query()->where('store_id', $store->id);
        if (($data['status'] ?? 'active') !== 'all') {
            $query->where('active', ($data['status'] ?? 'active') === 'active');
        }
        if (filled($data['q'] ?? null)) {
            $country = $limits->configured($store)['phone_country'];
            $canonical = $limits->normalizePhone($data['q'], $country);
            if ($canonical !== null) {
                $query->where('phone_normalized', $canonical);
            } else {
                $digits = strtr($data['q'], ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
                $digits = preg_replace('/[^0-9]/', '', $digits) ?? '';
                $digits === '' ? $query->whereRaw('1=0') : $query->where('phone_normalized', 'like', '%'.$digits.'%');
            }
        }
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 15);

        return response()->json(['data' => $page->items(), 'phone_country' => $limits->configured($store)['phone_country'],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function storePhone(Request $request, PhoneBlocking $blocking): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['phone' => ['required', 'string', 'max:50'], 'note' => ['nullable', 'string', 'max:500', 'regex:/^[^\x00-\x08\x0b\x0c\x0e-\x1f\x7f]*$/u']]);

        return response()->json(['data' => $blocking->add($store, $data['phone'], $data['note'] ?? null, $request->user()->id)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, Store $store, int $block, PhoneBlocking $blocking): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['active' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1'], 'note' => ['present', 'nullable', 'string', 'max:500', 'regex:/^[^\x00-\x08\x0b\x0c\x0e-\x1f\x7f]*$/u']]);

        return response()->json(['data' => $blocking->update($store, $block, (bool) $data['active'], $data['note'], (int) $data['version'], $request->user()->id)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function history(Request $request, Store $store, int $block): JsonResponse
    {
        $store = $this->store($request);
        StorePhoneBlock::query()->where('store_id', $store->id)->whereKey($block)->firstOrFail();
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = StorePhoneBlockEvent::query()->where('store_id', $store->id)->where('store_phone_block_id', $block)->with('actor:id,name')->orderByDesc('id')->paginate(25);

        return response()->json(['data' => collect($page->items())->map(fn ($event) => ['id' => $event->id, 'action' => $event->action, 'note' => $event->note, 'version' => $event->version,
            'actor' => $event->actor?->name, 'created_at' => $event->created_at])->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]], 200, ['Cache-Control' => 'private, no-store']);
    }
}

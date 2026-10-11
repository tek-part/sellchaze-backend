<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Storefront\UiBlocks;
use App\Support\Localization\LocaleContext;
use App\Support\ProductDescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreUiBlocksController extends Controller
{
    use ResolvesStorefront;

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.pages.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, Store $store, UiBlocks $blocks): JsonResponse
    {
        return response()->json(['data' => $blocks->configured($this->store($request))]);
    }

    public function update(Request $request, Store $store, UiBlocks $blocks): JsonResponse
    {
        $store = $this->store($request);
        $rules = ['enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1'],
            'blocks' => ['required', 'array:'.implode(',', UiBlocks::SLOTS)]];
        foreach (UiBlocks::SLOTS as $slot) {
            $rules['blocks.'.$slot] = ['required', 'array:editor,content'];
            $rules['blocks.'.$slot.'.editor'] = ['required', 'in:rich,html'];
            $rules['blocks.'.$slot.'.content'] = ['required', 'array:ar,en'];
            foreach (['ar', 'en'] as $locale) {
                $rules['blocks.'.$slot.'.content.'.$locale] = ['present', 'nullable', 'string', 'max:20000'];
            }
        }
        $data = $request->validate($rules);
        $updated = DB::transaction(function () use ($store, $blocks, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless($blocks->configured($locked)['version'] === (int) $data['version'], 409, 'UI blocks changed. Reload before saving.');
            $cleaned = [];
            foreach (UiBlocks::SLOTS as $slot) {
                $block = $data['blocks'][$slot];
                $cleaned[$slot] = ['editor' => $block['editor'], 'content' => [
                    'ar' => ProductDescription::clean($block['content']['ar'], true) ?? '',
                    'en' => ProductDescription::clean($block['content']['en'], true) ?? '',
                ]];
            }
            $locked->update(['ui_blocks_configuration' => ['enabled' => (bool) $data['enabled'], 'version' => (int) $data['version'] + 1, 'blocks' => $cleaned]]);

            return $locked;
        });

        return response()->json(['data' => $blocks->configured($updated)]);
    }

    public function publicConfiguration(Request $request, UiBlocks $blocks, LocaleContext $locale): JsonResponse
    {
        return response()->json(['data' => $blocks->publicConfiguration($this->currentStore($request), $locale->current())], 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Store;
use App\Services\Storefront\ThankYouPage;
use App\Support\ProductDescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreThankYouController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, ThankYouPage $page): JsonResponse
    {
        $store = $this->store($request);
        $categories = Category::withoutGlobalScopes()->where('store_id', $store->id)->where('is_active', true)
            ->whereNotNull('slug')->where('slug', '!=', '')->orderBy('position')->orderBy('id')->get();

        return response()->json(['data' => $page->configured($store), 'categories' => $categories->map(fn (Category $category) => ['id' => $category->id, 'slug' => $category->slug, 'name' => ['ar' => $category->translated('name', 'ar'), 'en' => $category->translated('name', 'en')]])]);
    }

    public function update(Request $request, ThankYouPage $page): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'],
            'content' => ['required', 'array:ar,en'], 'content.ar' => ['present', 'nullable', 'string', 'max:20000'], 'content.en' => ['present', 'nullable', 'string', 'max:20000'],
            'show_home_button' => ['required', 'boolean'], 'category_id' => ['present', 'nullable', 'integer', 'min:1']]);
        $updated = DB::transaction(function () use ($store, $page, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless($page->configured($locked)['version'] === (int) $data['version'], 409, 'Thank-you settings changed. Reload before saving.');
            if ($data['category_id'] !== null && ! Category::withoutGlobalScopes()->where('store_id', $store->id)
                ->where('is_active', true)->whereNotNull('slug')->where('slug', '!=', '')->whereKey($data['category_id'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['category_id' => 'Choose an active category from this store.']);
            }
            $locked->update(['thank_you_configuration' => ['enabled' => (bool) $data['enabled'],
                'content' => ['ar' => ProductDescription::clean($data['content']['ar'], true) ?? '', 'en' => ProductDescription::clean($data['content']['en'], true) ?? ''],
                'show_home_button' => (bool) $data['show_home_button'], 'category_id' => $data['category_id'], 'version' => (int) $data['version'] + 1]]);

            return $locked;
        });
        $request->attributes->set('store', $updated);

        return $this->index($request, $page);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Services\Commerce\ProductAbTests;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreAbTestsController extends Controller
{
    private function storeFor(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.products.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, ProductAbTests $tests, StorefrontUrlGenerator $urls)
    {
        $store = $this->storeFor($request);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $rows = DB::table('store_ab_tests')->where('store_id', $store->id)->whereNull('deleted_at')->orderByDesc('id')->paginate(20);

        return response()->json(['data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()], 'storefront_url' => $urls->publicUrl($store)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function products(Request $request, ProductAbTests $tests)
    {
        $store = $this->storeFor($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = $tests->products($store);
        if ($q = trim($data['q'] ?? '')) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]);
        }
        $rows = $query->orderByDesc('id')->paginate(20, ['id', 'name']);

        return response()->json(['data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function save(Request $request, ProductAbTests $tests)
    {
        $store = $this->storeFor($request);
        $id = $request->route('abTest');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'active' => ['required', 'boolean'],
            'version' => [$id ? 'required' : 'sometimes', 'integer', 'min:1'],
            'targets' => ['required', 'array', 'min:1', 'max:20'],
            'targets.*' => ['required', 'array:product_id,weight'],
            'targets.*.product_id' => ['required', 'integer', 'distinct', 'min:1'],
            'targets.*.weight' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);
        $test = $tests->save($store, $data, $id === null ? null : (int) $id);

        return response()->json(['data' => $test], $id === null ? 201 : 200);
    }

    public function show(Request $request, ProductAbTests $tests)
    {
        $store = $this->storeFor($request);
        $request->validate(['revisions_page' => ['sometimes', 'integer', 'min:1']]);
        $test = $tests->find($store, (int) $request->route('abTest'));
        $pages = DB::table('store_ab_revisions')->where('test_id', $test->id)->orderByDesc('version')->paginate(10, ['*'], 'revisions_page');
        $revisions = $pages->getCollection()->map(function ($revision) use ($store) {
            $targets = json_decode($revision->targets, true, 512, JSON_THROW_ON_ERROR);
            $products = Product::withoutGlobalScopes()->where('store_id', $store->id)->whereIn('id', array_column($targets, 'product_id'))->pluck('name', 'id');
            $visits = DB::table('store_ab_visits')->where('revision_id', $revision->id)->groupBy('product_id')->selectRaw('product_id, COUNT(*) as total')->pluck('total', 'product_id');
            $orders = DB::table('store_ab_conversions as c')->join('store_ab_visits as v', 'v.id', '=', 'c.visit_id')
                ->join('store_orders as o', 'o.id', '=', 'c.store_order_id')->where('v.revision_id', $revision->id)
                ->where('o.store_id', $store->id)->whereNull('o.deleted_at');
            $counts = (clone $orders)->groupBy('v.product_id')->selectRaw('v.product_id, COUNT(*) as orders, SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as cancelled', ['cancelled'])->get()->keyBy('product_id');
            $paid = (clone $orders)->where('o.payment_status', 'paid')->where('o.status', '!=', 'cancelled')
                ->selectRaw('v.product_id, o.currency, SUM(o.grand_total) as total')->groupBy('v.product_id', 'o.currency')->get()->groupBy('product_id');
            $revision->targets = array_map(function ($target) use ($products, $visits, $counts, $paid) {
                $id = $target['product_id'];

                return $target + ['name' => $products[$id] ?? '#'.$id, 'visits' => (int) ($visits[$id] ?? 0),
                    'orders' => (int) ($counts[$id]->orders ?? 0), 'cancelled_orders' => (int) ($counts[$id]->cancelled ?? 0),
                    'paid' => ($paid[$id] ?? collect())->map(fn ($sum) => ['currency' => $sum->currency, 'total' => $sum->total])->values()];
            }, $targets);

            return $revision;
        });

        return response()->json(['data' => $test, 'revisions' => $revisions, 'meta' => ['current_page' => $pages->currentPage(), 'last_page' => $pages->lastPage()]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, ProductAbTests $tests)
    {
        $store = $this->storeFor($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($store, $request, $tests, $data) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $test = $tests->find($store, (int) $request->route('abTest'));
            abort_unless((int) $test->version === $data['version'], 409, 'This test changed. Reload before deleting.');
            DB::table('store_ab_tests')->where('id', $test->id)->update(['active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->noContent();
    }
}

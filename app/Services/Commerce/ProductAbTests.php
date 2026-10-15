<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductAbTests
{
    public function products(Store $store)
    {
        return Product::query()->where('store_id', $store->id)->where('is_active', true)->whereNotNull('slug');
    }

    public function save(Store $store, array $data, ?int $id = null): object
    {
        return DB::transaction(function () use ($store, $data, $id) {
            // Serialize creation, edits and allocation for the same tenant.
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $old = $id === null ? null : $this->find($store, $id);
            abort_if($old && (int) $old->version !== (int) $data['version'], 409, 'This test changed. Reload before saving.');
            $targets = $data['targets'];
            if (array_sum(array_column($targets, 'weight')) !== 10000) {
                throw ValidationException::withMessages(['targets' => 'Traffic percentages must total 100%.']);
            }
            $ids = array_column($targets, 'product_id');
            if ($this->products($store)->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['targets' => 'Choose available products belonging to this store.']);
            }
            if (DB::table('store_ab_tests')->where('store_id', $store->id)->where('slug', $data['slug'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['slug' => 'This slug has already been used.']);
            }
            $version = $old ? (int) $old->version + 1 : 1;
            $values = ['name' => trim($data['name']), 'slug' => $data['slug'], 'active' => $data['active'], 'version' => $version, 'updated_at' => now()];
            if ($old) {
                DB::table('store_ab_tests')->where('id', $id)->update($values);
            } else {
                $id = DB::table('store_ab_tests')->insertGetId($values + ['store_id' => $store->id, 'created_at' => now()]);
            }
            DB::table('store_ab_revisions')->insert(['test_id' => $id, 'version' => $version, 'targets' => json_encode($targets, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $this->find($store, $id);
        });
    }

    public function find(Store $store, int $id): object
    {
        $test = DB::table('store_ab_tests')->where('store_id', $store->id)->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($test, 404);

        return $test;
    }

    public function resolve(Store $store, string $slug, string $visitor): array
    {
        return DB::transaction(function () use ($store, $slug, $visitor) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $test = DB::table('store_ab_tests')->where('store_id', $store->id)->where('slug', $slug)->where('active', true)->whereNull('deleted_at')->first();
            abort_unless($test, 404);
            $revision = DB::table('store_ab_revisions')->where('test_id', $test->id)->where('version', $test->version)->first();
            $hash = hash_hmac('sha256', $visitor, (string) config('app.key'));
            $token = hash_hmac('sha256', 'ab:'.$revision->id.':'.$hash, (string) config('app.key'));
            $visit = DB::table('store_ab_visits')->where('revision_id', $revision->id)->where('visitor_hash', $hash)->first();
            if (! $visit) {
                $targets = json_decode($revision->targets, true, 512, JSON_THROW_ON_ERROR);
                $bucket = hexdec(substr(hash('sha256', $revision->id.':'.$hash), 0, 7)) % 10000;
                $productId = self::select($targets, $bucket);
                // Never redistribute traffic silently when a target was removed.
                abort_unless($this->products($store)->whereKey($productId)->exists(), 404);
                $id = DB::table('store_ab_visits')->insertGetId(['revision_id' => $revision->id, 'visitor_hash' => $hash, 'token_hash' => hash('sha256', $token), 'product_id' => $productId, 'created_at' => now(), 'expires_at' => now()->addDays(30)]);
                $visit = DB::table('store_ab_visits')->where('id', $id)->first();
            }
            abort_if(now()->greaterThan($visit->expires_at), 410, 'This test visit expired.');
            $product = $this->products($store)->find($visit->product_id);
            abort_unless($product, 404);

            return ['product_id' => $product->id, 'slug' => $product->slug, 'token' => $token, 'expires_at' => Carbon::parse($visit->expires_at)->toIso8601String()];
        });
    }

    public static function select(array $targets, int $bucket): int
    {
        foreach ($targets as $target) {
            $bucket -= $target['weight'];
            if ($bucket < 0) {
                return (int) $target['product_id'];
            }
        }
        throw new \LogicException('Invalid A/B traffic allocation.');
    }

    public function attribute(Store $store, StoreOrder $order, ?string $token): void
    {
        if (! $token) {
            return;
        }
        // Attribution is optional: stale/forged capabilities never block a purchase.
        // Existing revision capabilities remain valid after edits or pausing.
        $visit = DB::table('store_ab_visits as v')->join('store_ab_revisions as r', 'r.id', '=', 'v.revision_id')
            ->join('store_ab_tests as t', 't.id', '=', 'r.test_id')->where('t.store_id', $store->id)
            ->where('v.token_hash', hash('sha256', $token))->where('v.expires_at', '>', now())->select('v.*')->first();
        if (! $visit || (int) $order->store_id !== (int) $store->id || ! $order->items()->where('store_product_id', $visit->product_id)->exists()) {
            return;
        }
        DB::table('store_ab_conversions')->insertOrIgnore(['visit_id' => $visit->id, 'store_order_id' => $order->id, 'created_at' => now()]);
    }
}

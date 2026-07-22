<?php

namespace App\Services\PageBuilder;

use App\Models\Store;
use App\Models\StoreReusableSection;
use App\Services\Storefront\StorefrontPageCache;

/**
 * Reusable/global sections (Task 3). A page section may reference one of these;
 * editing the reusable section updates every page that uses it (one edit,
 * many pages) — because pages reference it by id, not by copy.
 */
class ReusableSectionService
{
    public function __construct(private readonly StorefrontPageCache $cache) {}

    public function upsert(Store $store, array $data, ?StoreReusableSection $existing = null): StoreReusableSection
    {
        $section = $existing ?? new StoreReusableSection;
        $section->store_id = $store->id;
        if (array_key_exists('key', $data)) {
            $section->key = $data['key'];
        }
        if (array_key_exists('name', $data)) {
            $section->name = $data['name'];
        }
        if (array_key_exists('type', $data)) {
            $section->type = $data['type'];
        }
        if (array_key_exists('settings', $data)) {
            $section->settings = $data['settings'];
        }
        $section->save();

        $this->cache->flushStore($store->id); // affects every page that uses it

        return $section;
    }

    public function delete(StoreReusableSection $section): void
    {
        $storeId = $section->store_id;
        $section->delete();
        $this->cache->flushStore($storeId);
    }
}

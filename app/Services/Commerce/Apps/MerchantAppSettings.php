<?php

namespace App\Services\Commerce\Apps;

use App\Models\Store;

/** One merchant App store app whose settings live in a per-store JSON column. */
interface MerchantAppSettings
{
    /** The stores column that holds this app's settings. */
    public function column(): string;

    /** Saved settings merged over defaults; always carries `version` and `enabled`. */
    public function configured(Store $store): array;

    /** @return array<string, mixed> validation rules for a settings update (without `version`). */
    public function rules(Store $store): array;

    /** Validated input in its stored shape (without `version`). */
    public function normalize(Store $store, array $data): array;

    /** Whether the app currently changes the storefront or checkout. */
    public function enabled(Store $store): bool;

    /** Read-only facts the settings page needs (lists, capability notes). */
    public function meta(Store $store): array;
}

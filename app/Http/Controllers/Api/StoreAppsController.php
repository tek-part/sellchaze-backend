<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreSheetConnection;
use App\Services\Commerce\Apps\CountryRestriction;
use App\Services\Commerce\Apps\OrderProtection;
use App\Services\Commerce\Apps\ProductBundles;
use App\Services\Commerce\Apps\SocialProof;
use App\Services\Commerce\DownsellOffers;
use App\Services\Commerce\StoreTrackingSettings;
use App\Services\Stores\StoreManagers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreAppsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $registry = json_decode(file_get_contents(resource_path('data/merchant-apps.json')), true, flags: JSON_THROW_ON_ERROR);
        $tracking = app(StoreTrackingSettings::class)->configuration($store);
        $configured = [
            'conversion' => collect($tracking['conversions'])->contains(fn ($row) => $row['enabled']),
            'google-tag' => count($tracking['google']) > 0,
            'pixels' => count($tracking['facebook']) + count($tracking['tiktok']) + count($tracking['snapchat']) > 0,
            'google-merchant' => (bool) ($store->google_merchant_configuration['enabled'] ?? false),
            'otp' => (bool) ($store->phone_otp_configuration['enabled'] ?? false),
            'sheets' => StoreSheetConnection::forStore($store)->where('enabled', true)->exists(),
            'downsell' => app(DownsellOffers::class)->enabled($store),
            'minimum-order' => bccomp((string) ($store->order_limits['minimum_order_amount'] ?? '0'), '0', 2) > 0 || ($store->shipping_free_over !== null),
            'localization' => count($store->supported_locales ?? []) > 1,
            'social-proof' => app(SocialProof::class)->enabled($store),
            'country-restriction' => app(CountryRestriction::class)->enabled($store),
            'fake-orders' => app(OrderProtection::class)->enabled($store),
            'bundles' => app(ProductBundles::class)->enabled($store),
            'cross-selling' => app(ProductBundles::class)->enabled($store),
            'team' => StoreManagers::query($store, false)->exists(),
        ];
        $apps = array_map(function ($row) use ($configured, $request, $store) {
            $required = match ($row['key']) {
                'sheets' => ['store.settings.manage', 'store.orders.manage'],
                'downsell', 'bundles', 'cross-selling' => ['store.settings.manage', 'store.coupons.manage'],
                'coupons' => ['store.coupons.manage'],
                default => ['store.settings.manage'],
            };
            $owner = (int) $store->owner_user_id === (int) $request->user()->id;
            // Team accounts are managed by the store owner only.
            $permission = $row['key'] === 'team' ? ! $owner : ! $owner && collect($required)->contains(fn ($name) => ! $request->user()->can($name));

            return array_diff_key($row, ['reference_id' => true]) + ['included' => $row['available'], 'coming_soon' => ! $row['available'], 'configured' => $configured[$row['key']] ?? false,
                'can_manage' => $row['available'] && ! $permission, 'missing_permission' => $permission, 'monthly_charge' => null];
        }, $registry['apps']);

        return response()->json(['data' => $apps, 'categories' => $registry['categories']], 200, ['Cache-Control' => 'private, no-store']);
    }
}

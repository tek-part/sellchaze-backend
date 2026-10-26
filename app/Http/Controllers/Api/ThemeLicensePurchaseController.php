<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Theme;
use App\Models\ThemeLicensePurchase;
use App\Services\Themes\ThemeLicenseService;
use App\Services\Themes\ThemeLicensePurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeLicensePurchaseController extends Controller
{
    public function store(
        Request $request,
        Store $store,
        Theme $theme,
        ThemeLicensePurchaseService $purchases,
    ): JsonResponse {
        abort_unless($theme->is_marketplace, 404);

        $result = $purchases->createCheckout($store, $theme, $request->user());
        $purchase = $result['purchase'];

        return response()->json([
            'licensed' => $result['licensed'],
            'checkout_url' => $result['checkout_url'],
            'purchase' => $purchase ? [
                'id' => $purchase->id,
                'status' => $purchase->status,
                'amount' => $purchase->amount,
                'currency' => $purchase->currency,
                'expires_at' => $purchase->expires_at?->toIso8601String(),
            ] : null,
        ], $result['licensed'] ? 200 : 201);
    }

    public function status(
        Request $request,
        Store $store,
        string $session,
        ThemeLicenseService $licenses,
    ): JsonResponse {
        $purchase = ThemeLicensePurchase::query()
            ->where('store_id', $store->id)
            ->where('checkout_session_id', $session)
            ->with('theme')
            ->firstOrFail();

        return response()->json([
            'purchase' => [
                'id' => $purchase->id,
                'status' => $purchase->status,
                'amount' => $purchase->amount,
                'currency' => $purchase->currency,
                'paid_at' => $purchase->paid_at?->toIso8601String(),
            ],
            'licensed' => $purchase->theme
                ? $licenses->isLicensed($store, $purchase->theme)
                : false,
        ]);
    }
}

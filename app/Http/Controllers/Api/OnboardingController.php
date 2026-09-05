<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Product;
use App\Services\Stores\StoreProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Onboarding checklist for a newly registered merchant/supplier.
 *
 * Five steps, each derived from real state (never a stored flag that can drift):
 *   1. account created      — always done once authenticated
 *   2. company logo         — the store has a logo
 *   3. first 5 products     — product count toward a target of 5
 *   4. site template chosen — the store has an active theme
 *   5. invite first client  — at least one invitation sent
 */
class OnboardingController extends Controller
{
    private const PRODUCTS_TARGET = 5;

    /** GET /me/onboarding */
    public function show(Request $request, StoreProvisioner $provisioner): JsonResponse
    {
        $user = $request->user();
        // Owners always have exactly one store (provisioned on first access);
        // non-owners (Admin/Staff/Customer) get null and a store-less checklist.
        $store = $provisioner->ensureFor($user);

        $productCount = $store
            ? Product::query()->withoutGlobalScopes()->where('store_id', $store->id)->count()
            : Product::query()->withoutGlobalScopes()->where('user_id', $user->id)->count();

        $invitesSent = Invitation::query()->where('sender_user_id', $user->id)->count();

        $steps = [
            [
                'key' => 'account',
                'done' => true,
                'href' => null,
            ],
            [
                'key' => 'logo',
                'done' => (bool) ($store?->logo),
                'href' => '/store/settings/general',
            ],
            [
                'key' => 'products',
                'done' => $productCount >= self::PRODUCTS_TARGET,
                'progress' => ['current' => $productCount, 'target' => self::PRODUCTS_TARGET],
                'href' => '/products',
            ],
            [
                'key' => 'template',
                'done' => (bool) ($store?->theme_id),
                'href' => '/store/themes',
            ],
            [
                'key' => 'invite',
                'done' => $invitesSent > 0,
                'href' => '/partners',
            ],
        ];

        $doneCount = count(array_filter($steps, fn ($s) => $s['done']));

        return response()->json([
            'steps' => $steps,
            'done_count' => $doneCount,
            'total' => count($steps),
            'percent' => (int) round(($doneCount / count($steps)) * 100),
            'complete' => $doneCount === count($steps),
            'store' => $store ? ['id' => $store->id, 'name' => $store->name, 'status' => $store->status] : null,
        ]);
    }
}

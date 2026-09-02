<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Themes\ThemeLicensePurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeLicenseWebhookController extends Controller
{
    public function stripe(Request $request, ThemeLicensePurchaseService $purchases): JsonResponse
    {
        $purchases->handleStripeWebhook(
            $request->getContent(),
            (string) $request->header('Stripe-Signature')
        );

        return response()->json(['received' => true]);
    }
}

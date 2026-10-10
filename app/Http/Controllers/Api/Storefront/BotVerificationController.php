<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\CheckoutBotProtection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotVerificationController extends Controller
{
    use ResolvesStorefront;

    public function __invoke(Request $request, CheckoutBotProtection $protection): JsonResponse
    {
        $data = $request->validate(['nonce' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'token' => ['required', 'string', 'max:2048']]);

        return response()->json(['data' => $protection->verify($this->currentStore($request), $data['nonce'], $data['token'], $request->ip() ?? '')], 200, ['Cache-Control' => 'private, no-store']);
    }
}

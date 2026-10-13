<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MerchantSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MerchantSubscriptionsController extends Controller
{
    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && ! $user->parent_user_id && $user->hasAnyRole(['Merchant', 'Supplier']), 403);

        return $user;
    }

    public function show(Request $request, MerchantSubscriptions $billing): JsonResponse
    {
        $user = $this->owner($request);
        $active = $billing->active($user);
        $pref = DB::table('platform_subscription_preferences')->where('user_id', $user->id)->first();
        $rows = DB::table('platform_merchant_subscriptions')->where('user_id', $user->id)->latest('starts_at')->limit(12)->get();

        return response()->json(['plans' => collect(['monthly', 'yearly'])->map(fn ($cycle) => ['cycle' => $cycle, 'price_milli' => $billing->price($cycle), 'currency' => 'USD', 'quote' => $billing->quote($cycle)]),
            'available' => $billing->ready(), 'active' => $active ? $billing->present($active) : null,
            'preferences' => ['auto_renew' => (bool) ($pref->auto_renew ?? false), 'version' => (int) ($pref->version ?? 0)],
            'balance_milli' => (int) DB::table('platform_wallet_accounts')->where('user_id', $user->id)->value('balance_milli'), 'history' => $rows->map(fn ($row) => $billing->present($row))]);
    }

    public function purchase(Request $request, MerchantSubscriptions $billing): JsonResponse
    {
        $user = $this->owner($request);
        $data = $request->validate(['cycle' => ['required', Rule::in(['monthly', 'yearly'])], 'quote' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'request_key' => ['required', 'uuid'], 'auto_renew' => ['required', 'boolean']]);
        $row = $billing->purchase($user, $data['cycle'], $data['quote'], strtolower($data['request_key']), $data['auto_renew']);

        return response()->json(['data' => $billing->present($row)], 201);
    }

    public function preferences(Request $request, MerchantSubscriptions $billing): JsonResponse
    {
        $user = $this->owner($request);
        $data = $request->validate(['auto_renew' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        $row = $billing->preferences($user, $data['auto_renew'], $data['version']);

        return response()->json(['data' => ['auto_renew' => (bool) $row->auto_renew, 'version' => (int) $row->version]]);
    }
}

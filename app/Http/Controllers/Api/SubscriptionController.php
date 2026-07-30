<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesLocale;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The current user's plan + monthly posting quota, the list of plans, and (manual) plan activation.
 *
 * NOTE: there is no billing gateway yet, so `subscribe` just activates the chosen plan. This is the
 * placeholder seam a real payment integration will replace (create the subscription only after a
 * successful charge). Until then it is effectively an admin/manual toggle.
 */
class SubscriptionController extends Controller
{
    use ResolvesLocale;

    public function me(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $locale = $this->resolveLocale($request);

        return response()->json($subscriptions->quota($request->user(), $locale));
    }

    public function plans(Request $request): JsonResponse
    {
        $locale = $this->resolveLocale($request);

        $plans = Plan::query()->where('is_active', true)->orderBy('position')->get()
            ->map(fn (Plan $p) => [
                'slug' => $p->slug,
                'name' => $p->nameLocalized($locale),
                'post_limit_monthly' => $p->post_limit_monthly,
                'unlimited' => $p->isUnlimited(),
                'price' => (float) $p->price,
                'currency' => $p->currency,
                'trial_days' => $p->trial_days,
            ])->values()->all();

        return response()->json(['data' => $plans]);
    }

    public function subscribe(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,slug'],
        ]);

        $plan = Plan::query()->where('slug', $data['plan'])->where('is_active', true)->firstOrFail();
        $subscriptions->subscribe($request->user(), $plan);

        return response()->json([
            'message' => 'Plan activated.',
            'quota' => $subscriptions->quota($request->user()),
        ]);
    }
}

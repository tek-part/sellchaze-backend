<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Post;
use App\Models\Subscription;
use App\Models\User;

/**
 * Resolves a user's effective plan and enforces the monthly feed-posting quota.
 *
 * Plans are billing/commerce tiers (starter/professional/business). A user with
 * no live subscription defaults to the lowest tier. The optional feed-posting
 * limit lives in the plan's `quotas` map under `posts_monthly` (null = unlimited),
 * so tiers without that key leave posting open. Usage is counted from the posts
 * table for the current calendar month — no counter to keep in sync.
 */
class SubscriptionService
{
    private ?Plan $defaultPlan = null;

    /** The user's currently effective plan (active/trialing subscription, else the default tier). */
    public function planFor(User $user): Plan
    {
        $sub = Subscription::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->whereNull('current_period_end')->orWhere('current_period_end', '>', now());
            })
            ->latest('id')
            ->with('plan')
            ->first();

        if ($sub && $sub->plan) {
            return $sub->plan;
        }

        return $this->default();
    }

    public function monthlyPostCount(User $user): int
    {
        return (int) Post::query()
            ->where('user_id', $user->id)
            ->whereYear('published_at', now()->year)
            ->whereMonth('published_at', now()->month)
            ->count();
    }

    /** @return array<string, mixed> */
    public function quota(User $user, ?string $locale = null): array
    {
        $plan = $this->planFor($user);
        $limit = $plan->postLimitMonthly();             // null = unlimited
        $used = $this->monthlyPostCount($user);

        return [
            'plan' => [
                'slug' => $plan->slug,
                'name' => $plan->nameLocalized($locale),
                'price_monthly' => (float) $plan->price_monthly,
                'price_yearly' => (float) $plan->price_yearly,
                'currency' => $plan->currency,
                'features' => $plan->features,
                'quotas' => $plan->quotas,
            ],
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'unlimited' => $limit === null,
            'can_post' => $limit === null || $used < $limit,
        ];
    }

    public function canPost(User $user): bool
    {
        $limit = $this->planFor($user)->postLimitMonthly();
        if ($limit === null) {
            return true;
        }

        return $this->monthlyPostCount($user) < $limit;
    }

    /**
     * Activate a plan for the user. Until a payment gateway is wired this creates
     * the subscription directly (manual/admin activation); a real integration
     * would create it only after a successful charge.
     */
    public function subscribe(User $user, Plan $plan, string $cycle = 'monthly'): Subscription
    {
        // Retire any previous live subscription.
        Subscription::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $trialing = $plan->trial_days > 0;
        $now = now();
        $periodEnd = $cycle === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth();

        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => $trialing ? 'trialing' : 'active',
            'billing_cycle' => $cycle === 'yearly' ? 'yearly' : 'monthly',
            'trial_ends_at' => $trialing ? $now->copy()->addDays($plan->trial_days) : null,
            'current_period_start' => $now,
            'current_period_end' => $trialing ? $now->copy()->addDays($plan->trial_days) : $periodEnd,
        ]);
    }

    /** The default (lowest) tier used when a user has no live subscription. */
    private function default(): Plan
    {
        return $this->defaultPlan ??= Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->firstOrFail();
    }
}

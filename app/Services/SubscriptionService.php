<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Post;
use App\Models\Subscription;
use App\Models\User;

/**
 * Resolves a user's effective plan and enforces the monthly feed-posting quota.
 *
 * A user with no active subscription defaults to the free plan (3 posts/month). The paid plan is
 * unlimited. Usage is counted from the posts table for the current calendar month — no counter to
 * keep in sync. This is the single seam Phase 3 adds on top of the Phase 2 feed.
 */
class SubscriptionService
{
    private ?Plan $freePlan = null;

    /** The user's currently effective plan (active/trialing subscription, else the free default). */
    public function planFor(User $user): Plan
    {
        $sub = Subscription::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->latest('id')
            ->with('plan')
            ->first();

        if ($sub && $sub->plan) {
            return $sub->plan;
        }

        return $this->free();
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
        $limit = $plan->post_limit_monthly;              // null = unlimited
        $used = $this->monthlyPostCount($user);

        return [
            'plan' => [
                'slug' => $plan->slug,
                'name' => $plan->nameLocalized($locale),
                'price' => (float) $plan->price,
                'currency' => $plan->currency,
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
        $plan = $this->planFor($user);
        if ($plan->post_limit_monthly === null) {
            return true;
        }

        return $this->monthlyPostCount($user) < $plan->post_limit_monthly;
    }

    /** Activate a plan for the user (manual — until billing is wired). */
    public function subscribe(User $user, Plan $plan): Subscription
    {
        // Retire any previous active subscription.
        Subscription::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->update(['status' => 'cancelled', 'ends_at' => now()]);

        $trialing = $plan->trial_days > 0;

        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => $trialing ? 'trialing' : 'active',
            'started_at' => now(),
            'trial_ends_at' => $trialing ? now()->addDays($plan->trial_days) : null,
            'ends_at' => null,
        ]);
    }

    private function free(): Plan
    {
        return $this->freePlan ??= Plan::query()->where('slug', 'free_trial')->firstOrFail();
    }
}

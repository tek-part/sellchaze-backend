<?php

namespace App\Actions\Subscriptions;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\DB;

class SubscribeOrganizationAction
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function execute(Organization $organization, User $actor, Plan $plan, string $cycle): Subscription
    {
        return DB::transaction(function () use ($organization, $actor, $plan, $cycle): Subscription {
            Subscription::query()
                ->where('organization_id', $organization->id)
                ->whereIn('status', ['active', 'trialing'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $now = now();
            $trialing = $plan->trial_days > 0;
            $periodEnd = $cycle === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth();
            $subscription = Subscription::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $actor->id,
                'plan_id' => $plan->id,
                'status' => $trialing ? 'trialing' : 'active',
                'billing_cycle' => $cycle,
                'trial_ends_at' => $trialing ? $now->copy()->addDays($plan->trial_days) : null,
                'current_period_start' => $now,
                'current_period_end' => $trialing ? $now->copy()->addDays($plan->trial_days) : $periodEnd,
                'metadata' => ['source' => 'manual_v2'],
            ]);
            $this->outbox->record('SubscriptionActivated', 'organization', $organization->id, [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'plan' => $plan->slug,
                'billing_cycle' => $cycle,
            ]);

            return $subscription->load('plan');
        });
    }
}

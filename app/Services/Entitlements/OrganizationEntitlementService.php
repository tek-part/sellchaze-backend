<?php

namespace App\Services\Entitlements;

use App\Exceptions\QuotaExceededException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class OrganizationEntitlementService
{
    public function planFor(Organization $organization): Plan
    {
        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(fn ($query) => $query->whereNull('current_period_end')->orWhere('current_period_end', '>', now()))
            ->latest('id')
            ->with('plan')
            ->first();

        return $subscription?->plan
            ?? Plan::query()->where('slug', 'trial')->where('is_active', true)->firstOrFail();
    }

    public function feature(Organization $organization, string $key): bool
    {
        $value = $this->value($organization, $key, 'feature');

        return (bool) ($value?->value_boolean ?? false);
    }

    /** Null means explicitly unlimited; zero means unavailable. */
    public function quota(Organization $organization, string $key): ?int
    {
        $value = $this->value($organization, $key, 'quota');
        if (! $value) {
            return 0;
        }
        if ($value->value_integer === null) {
            return null;
        }

        return $value->value_integer + $this->addOnIncrement($organization, $key);
    }

    public function ensureQuota(Organization $organization, string $key, int $used, int $increment = 1): void
    {
        $limit = $this->quota($organization, $key);
        if ($limit !== null && $used + $increment > $limit) {
            throw new QuotaExceededException($key, $limit, $used);
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(Organization $organization): array
    {
        $plan = $this->planFor($organization);
        $values = $plan->entitlementValues()->with('entitlement')->get();
        $features = [];
        $quotas = [];
        foreach ($values as $value) {
            $definition = $value->entitlement;
            if ($definition->kind === 'feature') {
                $features[$definition->key] = (bool) $value->value_boolean;
            } else {
                $quotas[$definition->key] = $value->value_integer === null
                    ? null
                    : $value->value_integer + $this->addOnIncrement($organization, $definition->key);
            }
        }

        return [
            'plan' => ['id' => $plan->id, 'slug' => $plan->slug, 'name_en' => $plan->name_en, 'name_ar' => $plan->name_ar],
            'features' => $features,
            'quotas' => $quotas,
            'usage' => [
                'stores' => $organization->stores()->count(),
                'seats' => $organization->memberships()->where('status', 'active')->count(),
            ],
        ];
    }

    private function value(Organization $organization, string $key, string $kind): ?PlanEntitlement
    {
        return $this->planFor($organization)->entitlementValues()
            ->whereHas('entitlement', fn ($query) => $query->where('key', $key)->where('kind', $kind))
            ->first();
    }

    private function addOnIncrement(Organization $organization, string $key): int
    {
        return (int) DB::table('organization_add_ons')
            ->join('add_ons', 'add_ons.id', '=', 'organization_add_ons.add_on_id')
            ->join('entitlements', 'entitlements.id', '=', 'add_ons.entitlement_id')
            ->where('organization_add_ons.organization_id', $organization->id)
            ->where('organization_add_ons.status', 'active')
            ->where('add_ons.is_active', true)
            ->where('entitlements.key', $key)
            ->where(fn ($query) => $query->whereNull('organization_add_ons.ends_at')->orWhere('organization_add_ons.ends_at', '>', now()))
            ->sum(DB::raw('add_ons.increment * organization_add_ons.quantity'));
    }
}

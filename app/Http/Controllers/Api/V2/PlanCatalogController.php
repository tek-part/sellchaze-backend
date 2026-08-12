<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Plan;

class PlanCatalogController extends Controller
{
    public function __invoke()
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->where('target', 'company')
            ->with(['prices' => fn ($query) => $query->where('is_active', true), 'entitlementValues.entitlement'])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name_en' => $plan->name_en,
                'name_ar' => $plan->name_ar,
                'description_en' => $plan->description_en,
                'description_ar' => $plan->description_ar,
                'trial_days' => $plan->trial_days,
                'is_featured' => $plan->is_featured,
                'prices' => $plan->prices->map->only([
                    'country_code', 'currency', 'billing_cycle', 'amount',
                    'tax_inclusive', 'quote_required',
                ])->values(),
                'entitlements' => $plan->entitlementValues->mapWithKeys(fn ($value): array => [
                    $value->entitlement->key => $value->entitlement->kind === 'feature'
                        ? (bool) $value->value_boolean
                        : $value->value_integer,
                ]),
            ]);

        return response()->json(['data' => $plans]);
    }
}

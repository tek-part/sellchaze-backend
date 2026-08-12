<?php

namespace App\Http\Controllers\Api\V2;

use App\Actions\Subscriptions\SubscribeOrganizationAction;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationSubscriptionController extends Controller
{
    public function show(Request $request, Organization $organization, OrganizationEntitlementService $entitlements)
    {
        $this->authorize('view', $organization);

        return response()->json(['data' => $entitlements->snapshot($organization)]);
    }

    public function store(
        Request $request,
        Organization $organization,
        SubscribeOrganizationAction $subscribe,
        OrganizationEntitlementService $entitlements,
    ) {
        $this->authorize('update', $organization);
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'slug')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);
        $plan = Plan::query()->where('slug', $data['plan'])->where('is_active', true)->firstOrFail();
        $price = $plan->prices()->where('billing_cycle', $data['billing_cycle'])->where('is_active', true)->first();
        if (! $price) {
            throw ValidationException::withMessages(['plan' => 'No active price exists for this billing cycle.']);
        }
        if ($price->quote_required) {
            throw ValidationException::withMessages(['plan' => 'This plan requires a sales quotation.']);
        }

        $subscribe->execute($organization, $request->user(), $plan, $data['billing_cycle']);

        return response()->json(['data' => $entitlements->snapshot($organization)], 201);
    }
}

<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Organization;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Outbox\OutboxRecorder;
use App\Services\StoreService;
use App\Services\Themes\StoreThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationStoreController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        $this->authorize('view', $organization);

        return StoreResource::collection($organization->stores()->orderByDesc('is_primary')->orderBy('name')->paginate(50));
    }

    public function store(
        Request $request,
        Organization $organization,
        OutboxRecorder $outbox,
        OrganizationEntitlementService $entitlements,
        StoreService $stores,
        StoreThemeService $themes,
    ) {
        $this->authorize('update', $organization);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'alpha_dash:ascii', 'unique:stores,slug'],
            'description' => ['nullable', 'string', 'max:5000'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);
        $entitlements->ensureQuota($organization, 'stores', $organization->stores()->count());

        $store = DB::transaction(function () use ($request, $organization, $data, $outbox, $stores, $themes) {
            $isPrimary = ! $organization->stores()->exists();
            $store = $organization->stores()->create([
                ...$data,
                'owner_user_id' => $request->user()->id,
                'owner_type' => $request->user()->isSupplier() ? 'supplier' : 'merchant',
                'currency' => $data['currency'] ?? $organization->default_currency,
                'status' => 'draft',
                'is_primary' => $isPrimary,
            ]);
            $stores->syncSubdomain($store);
            $themes->installAndActivateDefault($store, $request->user()->id);
            $outbox->record('StoreCreated', 'store', $store->id, [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'owner_user_id' => $request->user()->id,
            ]);

            return $store;
        });

        return (new StoreResource($store))->response()->setStatusCode(201);
    }
}

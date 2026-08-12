<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Organization;
use App\Models\Store;
use App\Services\Outbox\OutboxRecorder;
use App\Services\Stores\StorePublishingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationStorePublishingController extends Controller
{
    public function readiness(
        Request $request,
        Organization $organization,
        Store $store,
        StorePublishingService $publishing,
    ) {
        $this->authorizeStore($request, $organization, $store);

        return response()->json(['data' => $publishing->readiness($store)]);
    }

    public function publish(
        Request $request,
        Organization $organization,
        Store $store,
        StorePublishingService $publishing,
        OutboxRecorder $outbox,
    ) {
        $this->authorizeStore($request, $organization, $store);
        $published = DB::transaction(function () use ($organization, $store, $publishing, $outbox) {
            $published = $publishing->publish($store);
            $outbox->record('StorePublished', 'store', (string) $store->id, [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
            ]);

            return $published;
        });

        return response()->json(['data' => new StoreResource($published)]);
    }

    public function unpublish(
        Request $request,
        Organization $organization,
        Store $store,
        StorePublishingService $publishing,
        OutboxRecorder $outbox,
    ) {
        $this->authorizeStore($request, $organization, $store);
        $draft = DB::transaction(function () use ($organization, $store, $publishing, $outbox) {
            $draft = $publishing->unpublish($store);
            $outbox->record('StoreUnpublished', 'store', (string) $store->id, [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
            ]);

            return $draft;
        });

        return response()->json(['data' => new StoreResource($draft)]);
    }

    private function authorizeStore(Request $request, Organization $organization, Store $store): void
    {
        $this->authorize('update', $organization);
        abort_unless($store->organization_id === $organization->id, 404);
    }
}

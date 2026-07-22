<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Resources\StoreDomainEventResource;
use App\Http\Resources\StoreDomainResource;
use App\Jobs\Domains\CheckDomainDnsJob;
use App\Jobs\Domains\IssueDomainCertificateJob;
use App\Jobs\Domains\RefreshSslStatusJob;
use App\Jobs\Domains\StartDomainVerificationJob;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreDomainEvent;
use App\Services\Stores\DomainHealthService;
use App\Services\Stores\StoreDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Custom-domain management for a store.
 *
 * Mounted under BOTH /stores/{store}/domains (admin) and /my-store/domains
 * (owner), so Suppliers and Merchants use one identical surface — the store is
 * already resolved into the route by ScopeToStore / ResolveOwnStore.
 *
 * Every action that touches DNS or a certificate authority is DISPATCHED, never
 * executed inline: these endpoints return 202 and the UI polls. No HTTP request
 * in this controller performs a DNS lookup.
 */
class StoreDomainsApiController extends Controller
{
    public function __construct(
        private readonly StoreDomainService $service,
        private readonly DomainHealthService $health,
    ) {}

    public function index(Request $request, Store $store): AnonymousResourceCollection
    {
        $this->authorize('manageDomains', $store);

        $domains = $store->domains()
            ->orderByDesc('is_primary')
            ->orderByRaw("CASE WHEN type = 'custom' THEN 0 ELSE 1 END")
            ->orderBy('host')
            ->get();

        return StoreDomainResource::collection($domains);
    }

    /** Connect a new custom domain. Starts PENDING — it serves nothing until verified. */
    public function store(StoreDomainRequest $request, Store $store): JsonResponse
    {
        $this->authorize('manageDomains', $store);

        $domain = $this->service->attach($store, (string) $request->validated('host'), $request->user());

        // Seed the DNS picture in the background so the wizard can show real
        // status immediately without the request waiting on a resolver.
        CheckDomainDnsJob::dispatch($domain->id);

        return (new StoreDomainResource($domain))->response()->setStatusCode(201);
    }

    /** Re-issue the DNS challenge token (rotates it, invalidating the old one). */
    public function startVerification(Request $request, Store $store, string $domain): StoreDomainResource
    {
        $this->authorize('manageDomains', $store);

        return new StoreDomainResource(
            $this->service->startVerification($this->find($store, $domain), $request->user()),
        );
    }

    /**
     * Queue a verification run. Returns 202 — the caller polls `show`/`health`.
     */
    public function verify(Request $request, Store $store, string $domain): JsonResponse
    {
        $this->authorize('manageDomains', $store);

        $model = $this->find($store, $domain);
        $this->service->assertNotLocked($model);

        StartDomainVerificationJob::dispatch($model->id);

        return response()->json([
            'queued' => true,
            'message' => __('Verification has been queued. This usually takes under a minute.'),
            'data' => new StoreDomainResource($model),
        ], 202);
    }

    /** Re-check DNS without rotating the challenge token. */
    public function refreshDns(Request $request, Store $store, string $domain): JsonResponse
    {
        $this->authorize('manageDomains', $store);

        $model = $this->find($store, $domain);
        $this->service->assertNotLocked($model);

        CheckDomainDnsJob::dispatch($model->id);

        return response()->json(['queued' => true, 'data' => new StoreDomainResource($model)], 202);
    }

    /** Queue a certificate issuance/renewal attempt. */
    public function retrySsl(Request $request, Store $store, string $domain): JsonResponse
    {
        $this->authorize('manageDomains', $store);

        $model = $this->find($store, $domain);

        // An operator-initiated retry clears the automatic give-up counter,
        // otherwise a domain that hit the cap could never be retried by hand.
        if ($model->ssl_renewal_attempts > 0) {
            $model->forceFill(['ssl_renewal_attempts' => 0])->save();
        }

        IssueDomainCertificateJob::dispatch($model->id, renewal: $model->ssl_fingerprint !== null);

        return response()->json(['queued' => true, 'data' => new StoreDomainResource($model->refresh())], 202);
    }

    /** Poll the provider for current certificate state. */
    public function refreshSsl(Request $request, Store $store, string $domain): JsonResponse
    {
        $this->authorize('manageDomains', $store);

        $model = $this->find($store, $domain);
        RefreshSslStatusJob::dispatch($model->id);

        return response()->json(['queued' => true, 'data' => new StoreDomainResource($model)], 202);
    }

    /** Promote a verified domain to the store's canonical host. */
    public function makePrimary(Request $request, Store $store, string $domain): StoreDomainResource
    {
        $this->authorize('manageDomains', $store);

        return new StoreDomainResource(
            $this->service->makePrimary($this->find($store, $domain), $request->user()),
        );
    }

    public function disable(Request $request, Store $store, string $domain): StoreDomainResource
    {
        $this->authorize('manageDomains', $store);

        return new StoreDomainResource(
            $this->service->disable($this->find($store, $domain), $request->user()),
        );
    }

    public function enable(Request $request, Store $store, string $domain): StoreDomainResource
    {
        $this->authorize('manageDomains', $store);

        return new StoreDomainResource(
            $this->service->enable($this->find($store, $domain), $request->user()),
        );
    }

    /** Full health report: DNS, TXT, target record, HTTPS, SSL, canonical, score. */
    public function health(Request $request, Store $store, string $domain): JsonResponse
    {
        $this->authorize('manageDomains', $store);

        return response()->json(['data' => $this->health->report($this->find($store, $domain))]);
    }

    /** Store-wide health summary, for the dashboard header. */
    public function healthSummary(Request $request, Store $store): JsonResponse
    {
        $this->authorize('manageDomains', $store);

        return response()->json(['data' => $this->health->summaryForStore($store)]);
    }

    /** Searchable, paginated audit history for one domain or the whole store. */
    public function events(Request $request, Store $store, ?string $domain = null): AnonymousResourceCollection
    {
        $this->authorize('manageDomains', $store);

        $query = StoreDomainEvent::query()
            ->where('store_id', $store->id)
            ->search($request->query('q') === null ? null : (string) $request->query('q'))
            ->latest('created_at');

        if ($domain !== null) {
            $query->where('store_domain_id', $this->find($store, $domain)->id);
        }

        if (($event = $request->query('event')) !== null) {
            $query->where('event', (string) $event);
        }

        return StoreDomainEventResource::collection(
            $query->paginate(min((int) $request->query('per_page', 25), 100)),
        );
    }

    public function destroy(Request $request, Store $store, string $domain): JsonResponse
    {
        $this->authorize('manageDomains', $store);

        $this->service->detach($this->find($store, $domain), $request->user());

        return response()->json(['deleted' => true]);
    }

    /**
     * Bind a domain that belongs to THIS store.
     *
     * Scoping the lookup to the store (rather than a global route-model bind) is
     * what stops one tenant addressing another tenant's domain row by id.
     */
    private function find(Store $store, string $domain): StoreDomain
    {
        return $store->domains()->whereKey($domain)->firstOrFail();
    }
}

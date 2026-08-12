<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ProcurementRequest;
use App\Models\ProcurementAuditEntry;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcurementRequestController extends Controller
{
    public function index(Request $request)
    {
        $organizationIds = $request->user()->organizationMemberships()
            ->where('status', 'active')
            ->pluck('organization_id');
        $sectorIds = DB::table('organization_sectors')->whereIn('organization_id', $organizationIds)->pluck('sector_id');

        $requests = ProcurementRequest::query()
            ->where(fn ($query) => $query
                ->where(fn ($visible) => $visible->where('status', 'published')
                    ->where(fn ($deadline) => $deadline->whereNull('response_deadline')->orWhere('response_deadline', '>', now()))
                    ->where(fn ($target) => $target
                        ->whereHas('selectedSupplierOrganizations', fn ($suppliers) => $suppliers->whereIn('organizations.id', $organizationIds))
                        ->orWhereIn('target_supplier_organization_id', $organizationIds)
                        ->orWhereIn('target_sector_id', $sectorIds)
                        ->orWhere(fn ($public) => $public
                            ->whereNull('target_supplier_organization_id')->whereNull('target_sector_id')
                            ->whereDoesntHave('selectedSupplierOrganizations'))))
                ->orWhereIn('buyer_organization_id', $organizationIds)
                ->orWhereHas('quotes', fn ($quotes) => $quotes
                    ->whereIn('supplier_organization_id', $organizationIds)))
            ->with(['buyerOrganization:id,name,slug', 'targetSupplierOrganization:id,name,slug', 'selectedSupplierOrganizations:id,name,slug', 'targetSector:id,slug,name'])
            ->withCount('quotes')
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 50));

        return response()->json($requests);
    }

    public function store(
        Request $request,
        OrganizationEntitlementService $entitlements,
        OutboxRecorder $outbox,
    ) {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'target_supplier_organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'target_supplier_organization_ids' => ['nullable', 'array', 'max:50'],
            'target_supplier_organization_ids.*' => ['integer', 'distinct', 'exists:organizations,id'],
            'target_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:10000'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit' => ['required', 'string', 'max:40'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'status' => ['nullable', 'in:draft,published'],
            'response_deadline' => ['nullable', 'date', 'after:now'],
            'metadata' => ['nullable', 'array'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.name' => ['required_with:items', 'string', 'max:180'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit' => ['required_with:items', 'string', 'max:40'],
            'items.*.specifications' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*.url' => ['required_with:attachments', 'url:http,https', 'max:2048'],
            'attachments.*.name' => ['nullable', 'string', 'max:255'],
            'attachments.*.type' => ['nullable', 'in:image,video,document'],
        ]);
        $organization = Organization::query()->findOrFail($data['organization_id']);
        $this->authorize('update', $organization);
        abort_if((int) ($data['target_supplier_organization_id'] ?? 0) === $organization->id, 422, 'Buyer and supplier must be different companies.');
        abort_if(in_array($organization->id, array_map('intval', $data['target_supplier_organization_ids'] ?? []), true), 422, 'Buyer and supplier must be different companies.');
        $targetModes = (isset($data['target_supplier_organization_id']) ? 1 : 0)
            + (! empty($data['target_supplier_organization_ids']) ? 1 : 0)
            + (isset($data['target_sector_id']) ? 1 : 0);
        abort_if($targetModes > 1, 422, 'Choose one RFQ audience: one supplier, selected suppliers, or a sector.');

        abort_unless($entitlements->feature($organization, 'rfqs'), 403, 'RFQs are not enabled for this organization.');
        if (isset($data['store_id'])) {
            abort_unless($organization->stores()->whereKey($data['store_id'])->exists(), 422, 'Store does not belong to the organization.');
        }

        $procurementRequest = DB::transaction(function () use ($request, $organization, $data, $outbox) {
            $supplierIds = $data['target_supplier_organization_ids'] ?? [];
            unset($data['organization_id'], $data['target_supplier_organization_ids']);
            $created = ProcurementRequest::query()->create([
                ...$data,
                'buyer_organization_id' => $organization->id,
                'created_by_user_id' => $request->user()->id,
                'currency' => $data['currency'] ?? $organization->default_currency,
                'status' => $data['status'] ?? 'draft',
            ]);
            $created->selectedSupplierOrganizations()->sync($supplierIds);
            ProcurementAuditEntry::query()->create([
                'procurement_request_id' => $created->id,
                'actor_user_id' => $request->user()->id,
                'event' => $created->status === 'published' ? 'rfq_published' : 'rfq_created',
                'to_status' => $created->status,
                'payload' => ['selected_supplier_ids' => $supplierIds, 'target_sector_id' => $created->target_sector_id],
            ]);
            $outbox->record('ProcurementRequestCreated', 'procurement_request', $created->id, [
                'procurement_request_id' => $created->id,
                'buyer_organization_id' => $organization->id,
                'status' => $created->status,
            ]);

            return $created;
        });

        return response()->json(['data' => $procurementRequest], 201);
    }

    public function show(Request $request, ProcurementRequest $procurementRequest)
    {
        $organizationIds = $request->user()->organizationMemberships()
            ->where('status', 'active')
            ->pluck('organization_id');
        $sectorIds = DB::table('organization_sectors')->whereIn('organization_id', $organizationIds)->pluck('sector_id');
        $isBuyerMember = $organizationIds->contains($procurementRequest->buyer_organization_id);
        $hasSupplierQuote = $procurementRequest->quotes()
            ->whereIn('supplier_organization_id', $organizationIds)
            ->exists();
        $hasSelectedSupplier = $procurementRequest->selectedSupplierOrganizations()->whereIn('organizations.id', $organizationIds)->exists();
        $hasAnySelected = $procurementRequest->selectedSupplierOrganizations()->exists();
        $isTargetSupplier = $hasSelectedSupplier
            || ($procurementRequest->target_supplier_organization_id !== null && $organizationIds->contains($procurementRequest->target_supplier_organization_id))
            || ($procurementRequest->target_sector_id !== null && $sectorIds->contains($procurementRequest->target_sector_id))
            || (! $hasAnySelected && $procurementRequest->target_supplier_organization_id === null && $procurementRequest->target_sector_id === null);
        $accepting = $procurementRequest->response_deadline === null || $procurementRequest->response_deadline->isFuture();
        abort_unless(($procurementRequest->status === 'published' && $accepting && $isTargetSupplier) || $isBuyerMember || $hasSupplierQuote, 404);

        $procurementRequest->load(['buyerOrganization:id,name,slug', 'targetSupplierOrganization:id,name,slug', 'selectedSupplierOrganizations:id,name,slug', 'targetSector:id,slug,name', 'order']);
        if ($isBuyerMember) {
            $procurementRequest->load('quotes.supplierOrganization:id,name,slug');
        } else {
            $procurementRequest->load([
                'quotes' => fn ($quotes) => $quotes
                    ->whereIn('supplier_organization_id', $organizationIds),
                'quotes.supplierOrganization:id,name,slug',
            ]);
        }

        return response()->json(['data' => $procurementRequest]);
    }

    public function audit(Request $request, ProcurementRequest $procurementRequest)
    {
        $organizationIds = $request->user()->organizationMemberships()->where('status', 'active')->pluck('organization_id');
        $isBuyer = $organizationIds->contains($procurementRequest->buyer_organization_id);
        $quoteIds = $procurementRequest->quotes()->whereIn('supplier_organization_id', $organizationIds)->pluck('id');
        abort_unless($isBuyer || $quoteIds->isNotEmpty(), 404);
        $entries = ProcurementAuditEntry::query()->where('procurement_request_id', $procurementRequest->id)
            ->when(! $isBuyer, fn ($query) => $query->where(fn ($visible) => $visible
                ->whereNull('procurement_quote_id')->orWhereIn('procurement_quote_id', $quoteIds)))
            ->orderBy('id')->get();

        return response()->json(['data' => $entries]);
    }
}

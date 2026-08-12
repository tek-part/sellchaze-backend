<?php

namespace App\Http\Controllers\Api\V2;

use App\Actions\Procurement\AcceptProcurementQuoteAction;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ProcurementQuote;
use App\Models\ProcurementRequest;
use App\Models\ProcurementAuditEntry;
use App\Models\ProcurementQuoteRevision;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcurementQuoteController extends Controller
{
    public function store(
        Request $request,
        ProcurementRequest $procurementRequest,
        OrganizationEntitlementService $entitlements,
        OutboxRecorder $outbox,
    ) {
        abort_unless($procurementRequest->status === 'published', 422, 'Request is not accepting quotes.');
        abort_if($procurementRequest->response_deadline?->isPast(), 422, 'The response deadline has passed.');
        $data = $request->validate([
            'supplier_organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'delivery_terms' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*.url' => ['required_with:attachments', 'url:http,https', 'max:2048'],
            'attachments.*.name' => ['nullable', 'string', 'max:255'],
        ]);
        $supplier = Organization::query()->findOrFail($data['supplier_organization_id']);
        $this->authorize('view', $supplier);
        abort_if($supplier->is($procurementRequest->buyerOrganization), 422, 'Buyer cannot quote its own request.');
        abort_if($procurementRequest->target_supplier_organization_id !== null
            && $procurementRequest->target_supplier_organization_id !== $supplier->id, 404);
        $hasSelected = $procurementRequest->selectedSupplierOrganizations()->exists();
        if ($hasSelected) {
            abort_unless($procurementRequest->selectedSupplierOrganizations()->whereKey($supplier->id)->exists(), 404);
        }
        if ($procurementRequest->target_sector_id !== null) {
            abort_unless($supplier->sectors()->whereKey($procurementRequest->target_sector_id)->exists(), 404);
        }
        abort_unless($entitlements->feature($supplier, 'rfqs'), 403, 'RFQs are not enabled for this organization.');

        if ($procurementRequest->quotes()->where('supplier_organization_id', $supplier->id)->exists()) {
            throw ValidationException::withMessages([
                'supplier_organization_id' => 'This organization already submitted a quote.',
            ]);
        }

        try {
            $quote = DB::transaction(function () use ($request, $procurementRequest, $supplier, $data, $outbox) {
                $created = ProcurementQuote::query()->create([
                    ...$data,
                    'procurement_request_id' => $procurementRequest->id,
                    'supplier_organization_id' => $supplier->id,
                    'submitted_by_user_id' => $request->user()->id,
                    'currency' => $data['currency'] ?? $procurementRequest->currency,
                    'version' => 1,
                ]);
                $outbox->record('ProcurementQuoteSubmitted', 'procurement_request', $procurementRequest->id, [
                    'procurement_request_id' => $procurementRequest->id,
                    'quote_id' => $created->id,
                    'supplier_organization_id' => $supplier->id,
                ]);
                ProcurementAuditEntry::query()->create([
                    'procurement_request_id' => $procurementRequest->id,
                    'procurement_quote_id' => $created->id,
                    'actor_user_id' => $request->user()->id,
                    'event' => 'quote_submitted',
                    'to_status' => 'submitted',
                    'payload' => ['version' => 1, 'supplier_organization_id' => $supplier->id],
                ]);

                return $created;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            throw ValidationException::withMessages([
                'supplier_organization_id' => 'This organization already submitted a quote.',
            ]);
        }

        return response()->json(['data' => $quote], 201);
    }

    public function update(
        Request $request,
        ProcurementRequest $procurementRequest,
        ProcurementQuote $procurementQuote,
        OutboxRecorder $outbox,
    ) {
        abort_unless($procurementQuote->procurement_request_id === $procurementRequest->id, 404);
        abort_unless($procurementRequest->status === 'published' && $procurementQuote->status === 'submitted', 422);
        abort_if($procurementRequest->response_deadline?->isPast(), 422, 'The response deadline has passed.');
        $supplier = Organization::query()->findOrFail($procurementQuote->supplier_organization_id);
        $this->authorize('update', $supplier);
        $data = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:8'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'delivery_terms' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*.url' => ['required_with:attachments', 'url:http,https', 'max:2048'],
            'attachments.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = DB::transaction(function () use ($request, $procurementRequest, $procurementQuote, $data, $outbox) {
            $locked = ProcurementQuote::query()->lockForUpdate()->findOrFail($procurementQuote->id);
            ProcurementQuoteRevision::query()->create([
                'procurement_quote_id' => $locked->id,
                'version' => $locked->version,
                'snapshot' => $locked->only(['amount', 'currency', 'lead_time_days', 'valid_until', 'notes', 'delivery_terms', 'attachments', 'status']),
                'created_by_user_id' => $request->user()->id,
            ]);
            $locked->update([...$data, 'version' => $locked->version + 1]);
            ProcurementAuditEntry::query()->create([
                'procurement_request_id' => $procurementRequest->id,
                'procurement_quote_id' => $locked->id,
                'actor_user_id' => $request->user()->id,
                'event' => 'quote_revised',
                'from_status' => 'submitted',
                'to_status' => 'submitted',
                'payload' => ['version' => $locked->version],
            ]);
            $outbox->record('ProcurementQuoteRevised', 'procurement_request', $procurementRequest->id, [
                'procurement_request_id' => $procurementRequest->id, 'quote_id' => $locked->id, 'version' => $locked->version,
            ]);

            return $locked->refresh();
        });

        return response()->json(['data' => $updated]);
    }

    public function compare(Request $request, ProcurementRequest $procurementRequest)
    {
        $this->authorize('update', $procurementRequest->buyerOrganization);
        $quotes = $procurementRequest->quotes()->with('supplierOrganization:id,name,slug')
            ->orderBy('amount')->orderBy('lead_time_days')->get();
        $lowest = $quotes->min(fn (ProcurementQuote $quote) => (float) $quote->amount);

        return response()->json(['data' => $quotes->map(fn (ProcurementQuote $quote) => [
            'id' => $quote->id,
            'supplier_organization' => $quote->supplierOrganization,
            'amount' => $quote->amount,
            'currency' => $quote->currency,
            'lead_time_days' => $quote->lead_time_days,
            'valid_until' => $quote->valid_until,
            'delivery_terms' => $quote->delivery_terms,
            'version' => $quote->version,
            'status' => $quote->status,
            'amount_above_lowest' => (float) $quote->amount - (float) $lowest,
        ])->values()]);
    }

    public function accept(
        Request $request,
        ProcurementRequest $procurementRequest,
        ProcurementQuote $procurementQuote,
        AcceptProcurementQuoteAction $action,
    ) {
        $buyer = $procurementRequest->buyerOrganization;
        $this->authorize('update', $buyer);

        return response()->json(['data' => $action->execute(
            $procurementRequest->id,
            $procurementQuote->id,
            $request->user()->id,
        )]);
    }
}

<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ProcurementAuditEntry;
use App\Models\ProcurementOrder;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProcurementOrderController extends Controller
{
    public function index(Request $request)
    {
        $organizationIds = $request->user()->organizationMemberships()
            ->where('status', 'active')
            ->pluck('organization_id');

        $orders = ProcurementOrder::query()
            ->where(fn ($query) => $query
                ->whereIn('buyer_organization_id', $organizationIds)
                ->orWhereIn('supplier_organization_id', $organizationIds))
            ->with(['buyerOrganization:id,name,slug', 'supplierOrganization:id,name,slug'])
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 50));

        return response()->json($orders);
    }

    public function show(Request $request, ProcurementOrder $procurementOrder)
    {
        abort_unless($this->canView($request, $procurementOrder), 404);

        return response()->json(['data' => $procurementOrder->load([
            'buyerOrganization:id,name,slug',
            'supplierOrganization:id,name,slug',
            'procurementRequest',
            'procurementQuote',
        ])]);
    }

    public function update(Request $request, ProcurementOrder $procurementOrder, OutboxRecorder $outbox)
    {
        abort_unless($this->canView($request, $procurementOrder), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['in_fulfillment', 'shipped', 'completed', 'cancelled'])],
        ]);
        $target = $data['status'];
        $requiredSide = match ([$procurementOrder->status, $target]) {
            ['confirmed', 'in_fulfillment'], ['in_fulfillment', 'shipped'] => 'supplier',
            ['shipped', 'completed'], ['confirmed', 'cancelled'] => 'buyer',
            default => null,
        };
        if ($requiredSide === null) {
            throw ValidationException::withMessages(['status' => 'Invalid procurement order status transition.']);
        }

        $organizationId = $requiredSide === 'buyer'
            ? $procurementOrder->buyer_organization_id
            : $procurementOrder->supplier_organization_id;
        $organization = Organization::query()->findOrFail($organizationId);
        $this->authorize('update', $organization);

        $expectedStatus = $procurementOrder->status;
        $updated = DB::transaction(function () use ($request, $procurementOrder, $expectedStatus, $target, $outbox) {
            $locked = ProcurementOrder::query()->lockForUpdate()->findOrFail($procurementOrder->id);
            if ($locked->status !== $expectedStatus) {
                throw ValidationException::withMessages(['status' => 'Procurement order status changed; reload and retry.']);
            }
            $from = $locked->status;
            $locked->update(['status' => $target]);
            $outbox->record('ProcurementOrderStatusChanged', 'procurement_order', $locked->id, [
                'procurement_order_id' => $locked->id,
                'from' => $from,
                'to' => $target,
            ]);
            ProcurementAuditEntry::query()->create([
                'procurement_request_id' => $locked->procurement_request_id,
                'procurement_quote_id' => $locked->procurement_quote_id,
                'procurement_order_id' => $locked->id,
                'actor_user_id' => $request->user()->id,
                'event' => 'order_status_changed',
                'from_status' => $from,
                'to_status' => $target,
            ]);

            return $locked->refresh();
        });

        return response()->json(['data' => $updated]);
    }

    private function canView(Request $request, ProcurementOrder $order): bool
    {
        return $request->user()->organizationMemberships()
            ->where('status', 'active')
            ->whereIn('organization_id', [$order->buyer_organization_id, $order->supplier_organization_id])
            ->exists();
    }
}

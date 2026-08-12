<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ProcurementRequest;
use App\Services\Procurement\ProcurementConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcurementConversationController extends Controller
{
    public function store(
        Request $request,
        ProcurementRequest $procurementRequest,
        ProcurementConversationService $conversations,
    ) {
        $data = $request->validate([
            'supplier_organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);
        $supplier = Organization::query()->findOrFail($data['supplier_organization_id']);
        abort_if($supplier->id === $procurementRequest->buyer_organization_id, 422, 'Buyer and supplier must be different companies.');
        abort_if($procurementRequest->target_supplier_organization_id !== null
            && $procurementRequest->target_supplier_organization_id !== $supplier->id, 404);

        $memberships = $request->user()->organizationMemberships()->where('status', 'active');
        $isBuyerMember = (clone $memberships)
            ->where('organization_id', $procurementRequest->buyer_organization_id)
            ->exists();
        $isSupplierMember = (clone $memberships)
            ->where('organization_id', $supplier->id)
            ->exists();
        abort_unless($isBuyerMember || $isSupplierMember, 404);

        $quote = $procurementRequest->quotes()
            ->where('supplier_organization_id', $supplier->id)
            ->first();
        if ($isSupplierMember) {
            abort_unless($procurementRequest->status === 'published' || $quote !== null, 404);
        }

        $conversation = DB::transaction(fn () => $conversations->getOrCreate(
            $procurementRequest,
            $supplier,
            $request->user()->id,
            $quote,
        ));

        return response()->json(['data' => $conversation], $conversation->wasRecentlyCreated ? 201 : 200);
    }
}

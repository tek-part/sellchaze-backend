<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ProcurementAuditEntry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizationAuditExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('scoped_organization');
        $this->authorize('update', $organization);
        $from = $request->date('from')?->startOfDay();
        $to = $request->date('to')?->endOfDay();
        $query = ProcurementAuditEntry::query()
            ->whereHas('procurementRequest', fn ($rfqs) => $rfqs->where('buyer_organization_id', $organization->id)
                ->orWhereHas('quotes', fn ($quotes) => $quotes->where('supplier_organization_id', $organization->id)))
            ->when($from, fn ($rows) => $rows->where('created_at', '>=', $from))
            ->when($to, fn ($rows) => $rows->where('created_at', '<=', $to))->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['id', 'occurred_at', 'event', 'request_id', 'quote_id', 'actor_user_id', 'from_status', 'to_status', 'payload']);
            $query->chunkById(500, function ($entries) use ($output): void {
                foreach ($entries as $entry) {
                    fputcsv($output, [$entry->id, $entry->created_at?->toIso8601String(), $entry->event,
                        $entry->procurement_request_id, $entry->procurement_quote_id, $entry->actor_user_id,
                        $entry->from_status, $entry->to_status, json_encode($entry->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            });
            fclose($output);
        }, 'sellchaze-audit-'.$organization->slug.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

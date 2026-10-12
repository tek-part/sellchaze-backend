<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreCheckoutLead;
use App\Services\Commerce\CheckoutLeads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreMissedOrdersController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['under_review', 'completed'])], 'search' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::in(['storefront', 'funnel'])], 'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $base = $this->missed($store);
        $counts = (clone $base)->selectRaw('review_status, COUNT(*) as total')->groupBy('review_status')->pluck('total', 'review_status');
        $query = $base->when(filled($filters['status'] ?? null), fn ($q) => $q->where('review_status', $filters['status']))
            ->when(filled($filters['source'] ?? null), fn ($q) => $q->where('source', $filters['source']))
            ->when(filled($filters['date_from'] ?? null), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']));
        if (filled($filters['search'] ?? null)) {
            $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(fn ($q) => $q->whereRaw("customer_name LIKE ? ESCAPE '!'", [$literal])
                ->orWhereRaw("customer_phone LIKE ? ESCAPE '!'", [$literal])->orWhereRaw("customer_email LIKE ? ESCAPE '!'", [$literal])
                ->orWhereRaw("phone_normalized LIKE ? ESCAPE '!'", [$literal]));
        }
        $paginator = $query->orderByDesc('last_activity_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => $paginator->getCollection()->map(fn ($lead) => $this->present($lead)),
            'counts' => ['under_review' => (int) ($counts['under_review'] ?? 0), 'completed' => (int) ($counts['completed'] ?? 0)],
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total(), 'per_page' => $paginator->perPage()]],
            200, ['Cache-Control' => 'private, no-store']);
    }

    public function bulkStatus(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['under_review', 'completed'])],
            'leads' => ['required', 'array', 'min:1', 'max:100'], 'leads.*.id' => ['required', 'integer', 'distinct', 'min:1'],
            'leads.*.version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($data, $store, $request): void {
            Store::query()->lockForUpdate()->findOrFail($store->id);
            $rows = $this->missed($store)->whereIn('id', array_column($data['leads'], 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($rows->count() === count($data['leads']), 404, 'A missed order is no longer available. Reload the list.');
            foreach ($data['leads'] as $selection) {
                abort_unless($rows[$selection['id']]->edit_version === (int) $selection['version'], 409, 'A missed order changed. Reload the list before updating it.');
            }
            foreach ($rows as $lead) {
                if ($lead->review_status !== $data['status']) {
                    $lead->update(['review_status' => $data['status'], 'edit_version' => $lead->edit_version + 1,
                        'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
                }
            }
        });

        return response()->json(['message' => 'Updated.'], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @return Builder<StoreCheckoutLead> */
    private function missed(Store $store): Builder
    {
        return StoreCheckoutLead::withoutGlobalScopes()->where('store_id', $store->id)->whereNull('converted_at')
            ->where('last_activity_at', '<=', now()->subMinutes(CheckoutLeads::INACTIVE_MINUTES))
            ->where(fn ($q) => $q->whereNotNull('customer_phone')->orWhereNotNull('customer_email'));
    }

    private function present(StoreCheckoutLead $lead): array
    {
        return ['id' => $lead->id, 'customer_name' => $lead->customer_name, 'customer_phone' => $lead->customer_phone,
            'customer_email' => $lead->customer_email, 'phone_dial' => $lead->phone_normalized, 'shipping_address' => $lead->shipping_address, 'notes' => $lead->notes,
            'shipping_selection' => $lead->shipping_selection, 'coupon_code' => $lead->coupon_code,
            'items' => $lead->items ?? [], 'subtotal' => $lead->subtotal, 'currency' => $lead->currency,
            'source' => $lead->source, 'source_path' => $lead->source_path, 'status' => $lead->review_status,
            'edit_version' => $lead->edit_version, 'last_activity_at' => $lead->last_activity_at?->toISOString(),
            'created_at' => $lead->created_at?->toISOString(), 'reviewed_at' => $lead->reviewed_at?->toISOString()];
    }
}

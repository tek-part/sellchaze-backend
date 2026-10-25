<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreCustomerProfile;
use App\Services\Commerce\StoreCustomerDirectory;
use App\Services\Commerce\StoreCustomerProfiles;
use App\Services\Commerce\StoreOrderExports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoreCustomersApiController extends Controller
{
    public function index(Request $request, Store $store, StoreCustomerProfiles $profiles): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:active,inactive']]);
        $query = StoreCustomer::query()->where('store_id', $store->id)
            ->select(['id', 'store_id', 'name', 'email', 'phone', 'is_active', 'created_at'])
            ->withCount('orders');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->get('status') === 'active');
        }
        $customers = $query->orderByDesc('id')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        $rows = collect($customers->items())->map(fn (StoreCustomer $customer) => $customer->toArray() + ['customer_key' => $profiles->accountKey($store, $customer)]);

        return response()->json(['data' => $rows, 'meta' => [
            'current_page' => $customers->currentPage(), 'last_page' => $customers->lastPage(),
            'total' => $customers->total(), 'per_page' => $customers->perPage(),
        ]]);
    }

    /** Customers built from orders (guests included), with Shopify-style segments and filters. */
    public function directory(Request $request, Store $store, StoreCustomerDirectory $directory): JsonResponse
    {
        $filters = $this->directoryFilters($request);
        $page = $directory->paginate($store, $filters, (int) ($filters['per_page'] ?? 25));

        return response()->json(['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(),
        ]]);
    }

    /** The whole filtered directory (not just one page) as CSV, streamed in chunks. */
    public function export(Request $request, Store $store, StoreCustomerDirectory $directory): StreamedResponse
    {
        $filters = $this->directoryFilters($request);

        return response()->streamDownload(function () use ($store, $filters, $directory): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic names correctly.
            fputcsv($out, ['Name', 'Phone', 'Email', 'Account', 'Orders', 'Amount spent', 'Average order', 'First order', 'Last order', 'Location', 'Tags', 'Accepts marketing'], ',', '"', '');
            $directory->each($store, $filters, function (Collection $rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(StoreOrderExports::cell(...), [
                        $row['name'], $row['phone'], $row['email'], $row['account_id'] ? 'registered' : 'guest', $row['orders_count'], number_format($row['total_spent'], 2, '.', ''),
                        $row['orders_count'] ? number_format($row['total_spent'] / $row['orders_count'], 2, '.', '') : '', $row['first_order_at'], $row['last_order_at'], $row['city'],
                        implode(', ', $row['tags']), $row['accepts_marketing'] ? 'yes' : 'no',
                    ]), ',', '"', '');
                }
            });
            fclose($out);
        }, 'customers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** GET customers/profile?key= — one directory customer (guest or registered): stats, addresses, orders, tags, note, consent. */
    public function profile(Request $request, Store $store, StoreCustomerProfiles $profiles): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $key = StoreCustomerProfiles::normalizeKey($data['key']);
        $customer = StoreCustomerProfiles::keyType($key) ? $profiles->show($store, $key, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 10)) : null;
        abort_if($customer === null, 404, __('Customer not found.'));

        return response()->json(['data' => $customer]);
    }

    /** PUT customers/profile — upsert tags, note, contact overrides and marketing consent; only sent fields change. */
    public function updateProfile(Request $request, Store $store, StoreCustomerProfiles $profiles): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:'.StoreCustomerProfile::MAX_TAGS],
            'tags.*' => ['nullable', 'string', 'max:'.StoreCustomerProfile::MAX_TAG_LENGTH],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StoreCustomerProfile::MAX_NOTE_LENGTH],
            'accepts_marketing' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ], [
            'tags.max' => __('A customer can have at most :max tags.'),
            'tags.*.max' => __('Each tag must be :max characters or fewer.'),
            'note.max' => __('The note must be :max characters or fewer.'),
            'email.email' => __('Enter a valid e-mail address.'),
        ]);
        $key = StoreCustomerProfiles::normalizeKey($data['key']);
        if (StoreCustomerProfiles::keyType($key) === null) {
            throw ValidationException::withMessages(['key' => __('No order or customer account in this store matches this customer.')]);
        }
        unset($data['key']);
        $profiles->update($store, $request->user(), $key, $data);

        return response()->json(['message' => __('Customer saved.'), 'data' => $profiles->show($store, $key)]);
    }

    /** GET customers/tags — distinct tags with customer counts (filters and autocomplete). */
    public function tags(Request $request, Store $store, StoreCustomerProfiles $profiles): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:40']]);

        return response()->json(['data' => $profiles->tags($store, $data['search'] ?? null)]);
    }

    /** @return array<string, mixed> */
    private function directoryFilters(Request $request): array
    {
        if ($request->has('tag') && ! is_array($request->input('tag'))) {
            $request->merge(['tag' => StoreCustomerDirectory::list($request->input('tag'))]);
        }

        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'orders_min' => ['nullable', 'integer', 'min:0'], 'orders_max' => ['nullable', 'integer', 'min:0'],
            'spent_min' => ['nullable', 'numeric', 'min:0'], 'spent_max' => ['nullable', 'numeric', 'min:0'],
            'last_order_from' => ['nullable', 'date'], 'last_order_to' => ['nullable', 'date'], 'first_order_from' => ['nullable', 'date'],
            'account' => ['nullable', 'in:registered,guest'],
            'tag' => ['nullable', 'array', 'max:20'], 'tag.*' => ['string', 'max:'.StoreCustomerProfile::MAX_TAG_LENGTH],
            'marketing' => ['nullable', 'in:subscribed,not_subscribed'],
            'sort_by' => ['nullable', 'in:'.implode(',', StoreCustomerDirectory::SORTS)], 'sort_direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    public function show(Request $request, Store $store, int $customer, StoreCustomerProfiles $profiles): JsonResponse
    {
        $model = StoreCustomer::query()->where('store_id', $store->id)
            ->select(['id', 'store_id', 'name', 'email', 'phone', 'is_active', 'created_at', 'last_login_at', 'email_verified_at'])
            ->withCount('orders')->findOrFail($customer);
        $model->setAttribute('customer_key', $profiles->accountKey($store, $model));
        $orders = $model->orders()->where('store_id', $store->id)
            ->select(['id', 'order_number', 'status', 'payment_status', 'currency', 'grand_total', 'placed_at'])
            ->orderByDesc('id')->paginate(10);

        return response()->json(['data' => $model, 'orders' => [
            'data' => $orders->items(), 'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()],
        ]]);
    }
}

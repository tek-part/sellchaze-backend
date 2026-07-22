<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreAddressRequest;
use App\Http\Resources\Storefront\CustomerAddressResource;
use App\Models\CustomerAddress;
use App\Models\StoreCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5: customer address book (store.customer). All queries are scoped to
 * both the current store (StoreScope) and the authenticated customer.
 */
class CustomerAddressController extends Controller
{
    use ResolvesStorefront;

    /** GET /storefront/account/addresses */
    public function index(Request $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $addresses = CustomerAddress::query()
            ->where('store_customer_id', $customer->id)
            ->orderByDesc('is_default')->orderBy('id')
            ->get();

        return response()->json(['data' => CustomerAddressResource::collection($addresses)]);
    }

    /** POST /storefront/account/addresses */
    public function store(StoreAddressRequest $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $data = $request->validated();

        $address = new CustomerAddress($data);
        $address->store_customer_id = $customer->id;
        $address->save();

        $this->syncDefault($customer, $address);

        return response()->json(['data' => new CustomerAddressResource($address)], 201);
    }

    /** PUT /storefront/account/addresses/{address} */
    public function update(StoreAddressRequest $request, int $address): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $model = $this->owned($customer, $address);
        $model->fill($request->validated())->save();

        $this->syncDefault($customer, $model);

        return response()->json(['data' => new CustomerAddressResource($model)]);
    }

    /** DELETE /storefront/account/addresses/{address} */
    public function destroy(Request $request, int $address): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $this->owned($customer, $address)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** Only one default per customer. */
    private function syncDefault(StoreCustomer $customer, CustomerAddress $address): void
    {
        if (! $address->is_default) {
            return;
        }
        CustomerAddress::query()
            ->where('store_customer_id', $customer->id)
            ->where('id', '!=', $address->id)
            ->update(['is_default' => false]);
    }

    private function owned(StoreCustomer $customer, int $id): CustomerAddress
    {
        $address = CustomerAddress::query()
            ->where('store_customer_id', $customer->id)
            ->find($id);
        abort_if($address === null, 404, 'Address not found.');

        return $address;
    }
}

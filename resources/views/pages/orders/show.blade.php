<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-lg-8">
            {{-- Order Items Card --}}
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Order') }} #{{ $order->code }}</h4>
                            <p class="mb-0 text-muted mt-1">{{ $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('d M Y \a\t H:i') : '—' }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('orders.out') }}" class="btn btn-light btn-sm me-1">
                                <i class="las la-arrow-left me-1"></i> {{ __('Back') }}
                            </a>
                            <a href="{{ route('tickets.create', $order->code) }}" class="btn btn-warning btn-sm me-1">
                                <i class="las la-ticket-alt me-1"></i> {{ __('Create Ticket') }}
                            </a>
                            @if(!OrderSupplierCheck($order->id))
                                <a href="{{ route('orders.edit', $order->code) }}" class="btn btn-primary btn-sm me-1">
                                    <i class="las la-pen me-1"></i> {{ __('Edit') }}
                                </a>
                                <form action="{{ route('orders.destroy', $order->code) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Are you sure you want to delete this order?') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="las la-trash-alt me-1"></i> {{ __('Delete') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            {{ $errors->first() }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Item') }}</th>
                                    <th class="text-end">{{ __('Quantity') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $orderProduct = $order->product ?? \App\Models\Product::find($order->product_id); @endphp
                                @if($orderProduct)
                                <tr>
                                    <td>
                                        @php $imgSrc = product_image($orderProduct); @endphp
                                        <img src="{{ $imgSrc }}" alt="" height="40" class="me-2 rounded align-middle" onerror="this.onerror=null; this.src='{{ placeholder_image('product') }}';">
                                        <p class="d-inline-block align-middle mb-0">
                                            <a href="{{ route('products.show', $orderProduct->id) }}" target="_blank" class="d-block align-middle mb-0 product-name text-body fw-semibold">{{ $orderProduct->name }}</a>
                                            <span class="text-muted font-13">ID: {{ $orderProduct->id }}</span>
                                        </p>
                                    </td>
                                    <td class="text-end align-middle"><span class="badge bg-primary-subtle text-primary">{{ formatNumber($order->quantity) }}</span></td>
                                </tr>
                                @endif

                                @if(!empty($order->wigpleasure_products))
                                    @php $orderProducts = is_array($order->wigpleasure_products) ? $order->wigpleasure_products : json_decode($order->wigpleasure_products, true); @endphp
                                    @if(!empty($orderProducts))
                                        @foreach($orderProducts as $wp)
                                        <tr>
                                            <td>
                                                @if(!empty($wp['image_url']))
                                                    <img src="{{ $wp['image_url'] }}" alt="" height="40" class="me-2 rounded align-middle">
                                                @else
                                                    <img src="{{ placeholder_image('product') }}" alt="" height="40" class="me-2 rounded align-middle">
                                                @endif
                                                <p class="d-inline-block align-middle mb-0">
                                                    <span class="d-block align-middle mb-0 product-name text-body fw-semibold">{{ $wp['title'] ?? '—' }}</span>
                                                    @if(!empty($wp['product_id']))<span class="text-muted font-13">{{ __('Product ID') }}: {{ $wp['product_id'] }}</span>@endif
                                                </p>
                                            </td>
                                            <td class="text-end align-middle">—</td>
                                        </tr>
                                        @endforeach
                                    @endif
                                @endif
                            </tbody>
                        </table>
                    </div>

                    {{-- Product Attributes --}}
                    @php
                        $attributes = [];
                        if (!empty($order->attributes)) {
                            $attrs = @unserialize($order->attributes);
                            $attributes = is_array($attrs) ? $attrs : [];
                        }
                    @endphp
                    @if(!empty($attributes))
                        <div class="mt-4 pt-3 border-top">
                            <h6 class="text-muted mb-2">{{ __('Product attributes') }}</h6>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach ($attributes as $attributeId => $attributeValues)
                                    @php $attributeModel = \App\Models\Attribute::find($attributeId); @endphp
                                    @if ($attributeModel)
                                        @if (is_array($attributeValues))
                                            @foreach ($attributeValues as $valueId)
                                                @php $valueModel = \App\Models\AttributeValues::find($valueId); @endphp
                                                @if ($valueModel)
                                                    <span class="badge bg-primary-subtle text-primary">{{ $attributeModel->name }}: {{ $valueModel->value }}</span>
                                                @endif
                                            @endforeach
                                        @else
                                            @php $valueModel = \App\Models\AttributeValues::find($attributeValues); @endphp
                                            @if ($valueModel)
                                                <span class="badge bg-primary-subtle text-primary">{{ $attributeModel->name }}: {{ $valueModel->value }}</span>
                                            @endif
                                        @endif
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($order->notes)
                        <div class="bg-primary-subtle p-3 border border-primary border-dashed rounded mt-3">
                            <span class="text-primary fw-semibold">{{ __('Note') }} :</span>
                            <span class="text-primary">{{ $order->notes }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            {{-- Order Summary --}}
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Order Summary') }}</h4>
                        </div>
                        <div class="col-auto">
                            @php
                                $statusClass = match(strtolower($order->status ?? '')) {
                                    'pending' => 'warning',
                                    'accepted', 'deal', 'completed' => 'success',
                                    'rejected', 'cancelled' => 'danger',
                                    default => 'secondary'
                                };
                            @endphp
                            <span class="badge rounded bg-{{ $statusClass }}-subtle text-{{ $statusClass }}">{{ ucfirst($order->status ?? '—') }}</span>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div class="d-flex justify-content-between mb-2">
                        <p class="text-body fw-semibold">{{ __('Quantity') }} :</p>
                        <p class="fw-semibold">{{ formatNumber($order->quantity) }}</p>
                    </div>
                    @if(!OrderSupplierCheck($order->id) && $order->quotations->count() > 0)
                        <div class="d-flex justify-content-between mb-2">
                            <p class="text-body fw-semibold">{{ __('Quotations') }} :</p>
                            <a href="{{ route('orders.quotations', $order->code) }}" class="badge bg-success-subtle text-success text-decoration-none">{{ $order->quotations->count() }}</a>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Order Information --}}
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('Order Information') }}</h4>
                </div>
                <div class="card-body pt-0">
                    @php $orderUser = getUserInfo($order->user_id); @endphp
                    <div class="d-flex justify-content-between mb-2">
                        <p class="text-body fw-semibold"><i class="las la-hashtag text-secondary fs-6 align-middle me-1"></i>{{ __('Order code') }} :</p>
                        <p class="fw-semibold">{{ $order->code }}</p>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <p class="text-body fw-semibold"><i class="las la-tag text-secondary fs-6 align-middle me-1"></i>{{ __('Ref. number') }} :</p>
                        <p class="fw-semibold">{{ $order->ref_number ?? '—' }}</p>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <p class="text-body fw-semibold"><i class="las la-user text-secondary fs-6 align-middle me-1"></i>{{ __('Ordered by') }} :</p>
                        <p class="fw-semibold">
                            @php
                                $hasStoreCustomer = !empty($order->customer_name) || !empty($order->customer_email);
                                $displayName = $order->customer_name ?? optional($orderUser)->name ?? null;
                            @endphp
                            @if($displayName)
                                @if(!$hasStoreCustomer && $orderUser && $orderUser->profile)
                                    <a href="{{ route('profile.show', $orderUser->profile->username) }}" class="text-primary text-decoration-none">{{ $displayName }}</a>
                                @else
                                    {{ $displayName }}
                                    @if(!empty($order->customer_email))
                                        <small class="text-muted d-block">{{ $order->customer_email }}</small>
                                    @endif
                                @endif
                            @else
                                —
                            @endif
                        </p>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <p class="text-body fw-semibold"><i class="las la-calendar text-secondary fs-6 align-middle me-1"></i>{{ __('Order date') }} :</p>
                        <p class="fw-semibold">{{ $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('d M Y, H:i') : '—' }}</p>
                    </div>
                    @if($order->shipping_address || $order->shipping_address_json)
                    <div class="d-flex justify-content-between mb-2">
                        <p class="text-body fw-semibold"><i class="las la-map-marker text-secondary fs-6 align-middle me-1"></i>{{ __('Shipping address') }} :</p>
                    </div>
                    <p class="text-muted small mb-0">{{ $order->shipping_address ?? (is_string($order->shipping_address_json) ? $order->shipping_address_json : json_encode($order->shipping_address_json ?? [])) }}</p>
                    @endif
                    @if($order->payment_type === 'partial_cod' && $order->paid_amount)
                    <div class="d-flex justify-content-between mt-2 mb-2">
                        <p class="text-body fw-semibold"><i class="las la-money-bill text-secondary fs-6 align-middle me-1"></i>{{ __('COD Amount') }} :</p>
                        <p class="fw-semibold text-warning">{{ formatNumber(($order->quotations->first()->price ?? 0) - $order->paid_amount, 2) }}</p>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Deliveries --}}
            @can('deliveries-update')
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('Deliveries') }}</h4>
                </div>
                <div class="card-body pt-0">
                    @if($order->deliveries->isNotEmpty())
                        @foreach($order->deliveries as $d)
                        <div class="d-flex justify-content-between align-items-center mb-2 py-2 border-bottom">
                            <div>
                                <span class="badge bg-primary-subtle text-primary">{{ ucfirst($d->delivery_company) }}</span>
                                <span class="ms-2 text-muted">{{ $d->tracking_number ?? '—' }}</span>
                                <span class="badge bg-{{ $d->status === 'delivered' ? 'success' : 'warning' }}-subtle text-{{ $d->status === 'delivered' ? 'success' : 'warning' }} ms-1">{{ ucfirst($d->status) }}</span>
                            </div>
                        </div>
                        @endforeach
                    @endif
                    <form method="post" action="{{ route('deliveries.store') }}" class="mt-3">
                        @csrf
                        <input type="hidden" name="order_id" value="{{ $order->id }}">
                        <div class="mb-2">
                            <label class="form-label small">{{ __('Delivery company') }}</label>
                            <select name="delivery_company" class="form-select form-select-sm" required>
                                <option value="aramex">Aramex</option>
                                <option value="careem">Careem</option>
                                <option value="yahiya">Yahiya</option>
                                <option value="manual">{{ __('Manual') }}</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">{{ __('Tracking number') }}</label>
                            <input type="text" name="tracking_number" class="form-control form-control-sm" placeholder="{{ __('Optional') }}">
                        </div>
                        @if($order->payment_type === 'partial_cod')
                        <div class="mb-2">
                            <label class="form-label small">{{ __('COD Amount') }}</label>
                            <input type="number" name="cod_amount" class="form-control form-control-sm" step="0.01" min="0" placeholder="{{ __('Amount to collect') }}">
                        </div>
                        @endif
                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="las la-truck me-1"></i> {{ __('Add delivery') }}</button>
                    </form>
                </div>
            </div>
            @endcan

            {{-- Order Images --}}
            @php
                $allOrderImages = [];
                if (!empty($order->image)) {
                    $allOrderImages[] = ['url' => $order->image, 'title' => __('Order image')];
                }
                if (!empty($order->wigpleasure_products)) {
                    $wpProducts = is_array($order->wigpleasure_products) ? $order->wigpleasure_products : json_decode($order->wigpleasure_products, true);
                    if (!empty($wpProducts)) {
                        foreach ($wpProducts as $product) {
                            if (!empty($product['image_url'])) {
                                $allOrderImages[] = ['url' => $product['image_url'], 'title' => $product['title'] ?? __('Product')];
                            }
                        }
                    }
                }
            @endphp
            @if(count($allOrderImages) > 0)
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('Order images') }} ({{ count($allOrderImages) }})</h4>
                    </div>
                    <div class="card-body pt-0">
                        <div class="row g-2">
                            @foreach($allOrderImages as $img)
                                @php
                                    $isExternal = str_starts_with($img['url'], 'http://') || str_starts_with($img['url'], 'https://');
                                    $src = $isExternal ? $img['url'] : asset('/storage/uploads/orders/thumbnails/'.$img['url']);
                                    $href = $isExternal ? $img['url'] : asset('/storage/uploads/orders/original/'.$img['url']);
                                @endphp
                                <div class="col-6">
                                    <a href="{{ $href }}" target="_blank" class="d-block">
                                        <img src="{{ $src }}" alt="{{ $img['title'] }}" class="rounded w-100" style="height:120px;object-fit:cover;" onerror="this.src='{{ placeholder_image('order') }}'">
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- Supplier: Submit quotation --}}
            @if($OrderSupplierCheck == true)
                @if($orderQuotationCheck == true && $orderQuotation)
                    <div class="card border-success">
                        <div class="card-header bg-success-subtle">
                            <h4 class="card-title text-success mb-0">{{ __('Quotation accepted') }}</h4>
                        </div>
                        <div class="card-body pt-0">
                            <p class="text-muted mb-3">{{ __('Waiting for the customer to approve your quotation.') }}</p>
                            <hr>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Price') }}</span><span class="fw-bold">{{ formatNumber($orderQuotation->price, 2) }} {{ $orderQuotation->currency ?? 'EGP' }}</span></div>
                            @if($orderQuotation->shipping_company)
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Shipping company') }}</span><span class="fw-semibold">{{ $orderQuotation->shipping_company }}</span></div>
                            @endif
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Delivery date') }}</span><span class="fw-semibold">{{ $orderQuotation->delivery_date }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Status') }}</span><span class="badge bg-success-subtle text-success">{{ ucfirst($orderQuotation->status) }}</span></div>
                            @if($orderQuotation->tracking_number)
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Tracking number') }}</span><span class="fw-semibold">{{ $orderQuotation->tracking_number }}</span></div>
                            @endif
                            @if($orderQuotation->notes)
                                <div class="mb-2"><span class="text-muted">{{ __('Notes') }}</span><div class="mt-1">{{ $orderQuotation->notes }}</div></div>
                            @endif
                            @if(in_array($orderQuotation->status, ['accepted', 'deal']) && $orderQuotation->supplier_user_id == Auth::id() && !$orderQuotation->tracking_number)
                            <hr>
                            <form method="post" action="{{ route('quotations.add-tracking', $orderQuotation->id) }}" class="mt-3">
                                @csrf
                                <div class="mb-2">
                                    <label for="tracking_number" class="form-label fw-semibold">{{ __('Add tracking number') }}</label>
                                    <input type="text" name="tracking_number" id="tracking_number" class="form-control" placeholder="{{ __('Tracking number') }}" required>
                                </div>
                                <button type="submit" class="btn btn-sm btn-success"><i class="las la-truck me-1"></i> {{ __('Mark as shipped') }}</button>
                            </form>
                            @endif
                            @php
                                $customerUser = \App\Models\User::find($orderQuotation->customer_user_id);
                                $custDisplayName = $order->customer_name ?? optional($customerUser)->name;
                            @endphp
                            @if($custDisplayName)
                                @if($customerUser && $customerUser->profile)
                                    <div><span class="text-muted">{{ __('Customer') }}</span><div class="mt-1"><a href="{{ route('profile.show', $customerUser->profile->username) }}" target="_blank" class="text-primary text-decoration-none">{{ $custDisplayName }}</a></div></div>
                                @else
                                    <div><span class="text-muted">{{ __('Customer') }}</span><div class="mt-1">{{ $custDisplayName }}</div></div>
                                @endif
                            @endif
                        </div>
                    </div>
                @else
                    <div class="card border-primary">
                        <div class="card-header bg-primary-subtle">
                            <h4 class="card-title text-primary mb-0">{{ __('Submit your quotation') }}</h4>
                        </div>
                        <div class="card-body pt-0">
                            <form method="post" action="{{ route('quotations.accept', $order->code) }}">
                                @csrf
                                <div class="mb-3">
                                    <label for="price" class="form-label fw-semibold">{{ __('Price (incl. shipping)') }}</label>
                                    <input type="number" name="price" id="price" class="form-control" step="0.01" min="0" required placeholder="{{ __('Total price including shipping') }}">
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="currency" class="form-label fw-semibold">{{ __('Currency') }}</label>
                                        <select name="currency" id="currency" class="form-select">
                                            <option value="EGP">EGP</option>
                                            <option value="AED">AED</option>
                                            <option value="USD">USD</option>
                                            <option value="SAR">SAR</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="shipping_company" class="form-label fw-semibold">{{ __('Shipping company') }}</label>
                                        <input type="text" name="shipping_company" id="shipping_company" class="form-control" placeholder="{{ __('e.g. Aramex, DHL') }}">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="price_includes_shipping" id="price_includes_shipping" value="1" class="form-check-input" checked>
                                        <label for="price_includes_shipping" class="form-check-label">{{ __('Price includes shipping') }}</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="delivery_date" class="form-label fw-semibold">{{ __('Estimated delivery date') }}</label>
                                    <input type="date" name="delivery_date" id="delivery_date" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label for="notes" class="form-label fw-semibold">{{ __('Notes') }}</label>
                                    <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="{{ __('Notes for this quotation') }}"></textarea>
                                </div>
                                <button type="submit" name="action" value="skip" class="btn btn-primary w-100">
                                    <i class="las la-check me-2"></i> {{ __('Accept & submit quotation') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            @else
                {{-- Customer: View quotations --}}
                @if($order->quotations->count() > 0)
                    <div class="card">
                        <div class="card-header">
                            <div class="row align-items-center">
                                <div class="col">
                                    <h4 class="card-title mb-0">{{ __('Quotations for this order') }}</h4>
                                </div>
                                <div class="col-auto">
                                    <a href="{{ route('orders.quotations', $order->code) }}" class="btn btn-sm btn-primary">{{ __('View all') }}</a>
                                </div>
                            </div>
                        </div>
                        <div class="card-body pt-0">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr class="text-muted fs-7">
                                            <th>{{ __('Supplier') }}</th>
                                            <th>{{ __('Price') }}</th>
                                            <th>{{ __('Delivery') }}</th>
                                            <th>{{ __('Status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($order->quotations->take(5) as $quotation)
                                            @php $supplierUser = \App\Models\User::find($quotation->supplier_user_id); @endphp
                                            <tr>
                                                <td>
                                                    @if($supplierUser && $supplierUser->profile)
                                                        <a href="{{ route('profile.show', $supplierUser->profile->username) }}" target="_blank" class="text-primary text-decoration-none fw-semibold">{{ $supplierUser->name }}</a>
                                                    @else
                                                        <span class="fw-semibold">{{ $supplierUser->name ?? '—' }}</span>
                                                    @endif
                                                </td>
                                                <td class="fw-bold">{{ formatNumber($quotation->price, 2) }}</td>
                                                <td class="text-muted fs-7">{{ $quotation->delivery_date }}</td>
                                                <td>
                                                    @if($quotation->status == 'pending')
                                                        <span class="badge bg-warning-subtle text-warning">{{ __('Pending') }}</span>
                                                    @elseif($quotation->status == 'accepted' || $quotation->status == 'deal')
                                                        <span class="badge bg-success-subtle text-success">{{ ucfirst($quotation->status) }}</span>
                                                    @else
                                                        <span class="badge bg-danger-subtle text-danger">{{ ucfirst($quotation->status ?? '—') }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <x-slot name="script">
    <script>window.chatOrderCode = "{{ $order->code }}"; window.chatOrderId = {{ $order->id }};</script>
    </x-slot>
</x-default-layout>

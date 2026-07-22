<div class="dropdown-menu stop dropdown-menu-end dropdown-lg py-0">
    <h5 class="dropdown-item-text m-0 py-3 d-flex justify-content-between align-items-center">
        {{ __('Notifications') }}
        <span class="badge text-body-tertiary badge-pill">{{ formatNumber(count($orders_notifications ?? []) + count($quotations_notifications ?? [])) }}</span>
    </h5>
    <ul class="nav nav-tabs nav-tabs-custom nav-success nav-justified mb-1" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link mx-0 active" data-bs-toggle="tab" href="#rizzNotifQuotations" role="tab">{{ __('Quotations') }}
                <span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(count($quotations_notifications ?? [])) }}</span>
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link mx-0" data-bs-toggle="tab" href="#rizzNotifOrders" role="tab">{{ __('Orders') }}
                <span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(count($orders_notifications ?? [])) }}</span>
            </a>
        </li>
    </ul>
    <div class="ms-0" style="max-height:230px;" data-simplebar>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="rizzNotifQuotations">
                @forelse($quotations_notifications ?? [] as $notification)
                    @php
                        $alert = ["product" => "primary", "quotation" => "primary", "order" => "danger", "deal" => "success"];
                        $order = App\Models\Order::find($notification->data['order_id'] ?? null);
                        $days = Carbon\Carbon::now()->diff($notification->created_at)->days;
                        $timeAgo = $days < 1 ? 'Today' : ($days == 1 ? '1 day ago' : $days . ' days ago');
                    @endphp
                    <a href="{{ $order ? route('orders.show', $order->code) : '#' }}" class="dropdown-item py-3">
                        <small class="float-end text-muted ps-2">{{ $timeAgo }}</small>
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-primary-subtle text-primary thumb-md rounded-circle">
                                <i class="iconoir-document fs-4"></i>
                            </div>
                            <div class="flex-grow-1 ms-2 text-truncate">
                                <h6 class="my-0 fw-normal text-dark fs-13">{{ ucfirst($notification->data['type'] ?? '') }} {{ ($notification->data['action'] ?? '') . 'd' }}</h6>
                                <small class="text-muted mb-0">{{ $order ? $order->code : '-' }}</small>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="dropdown-item py-3 text-muted text-center">{{ __('No quotations notifications') }}</div>
                @endforelse
            </div>
            <div class="tab-pane fade" id="rizzNotifOrders">
                @forelse($orders_notifications ?? [] as $notification)
                    @php
                        $order = App\Models\Order::find($notification->data['order_id'] ?? null);
                        $days = Carbon\Carbon::now()->diff($notification->created_at)->days;
                        $timeAgo = $days < 1 ? 'Today' : ($days == 1 ? '1 day ago' : $days . ' days ago');
                    @endphp
                    <a href="{{ $order ? route('orders.show', $order->code) : '#' }}" class="dropdown-item py-3">
                        <small class="float-end text-muted ps-2">{{ $timeAgo }}</small>
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-primary-subtle text-primary thumb-md rounded-circle">
                                <i class="iconoir-delivery-truck fs-4"></i>
                            </div>
                            <div class="flex-grow-1 ms-2 text-truncate">
                                <h6 class="my-0 fw-normal text-dark fs-13">{{ ucfirst($notification->data['type'] ?? '') }} {{ ($notification->data['action'] ?? '') . 'd' }}</h6>
                                <small class="text-muted mb-0">{{ $order ? $order->code : '-' }}</small>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="dropdown-item py-3 text-muted text-center">{{ __('No orders notifications') }}</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="dropdown-divider my-0"></div>
    <a href="{{ route('notifications.orders') }}" class="dropdown-item text-center text-dark fs-13 py-2">
        {{ __('View All') }} <i class="iconoir-arrow-right ms-1"></i>
    </a>
</div>

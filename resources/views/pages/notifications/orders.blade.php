<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @if(count($notifications) > 0)
        <div class="card shadow-sm mb-0">
            @include(config('settings.KT_THEME_LAYOUT_DIR').'.partials.toolbars.notifications.orders')

            <div class="card-body pt-0">
                {{-- Header banner --}}
                <div class="card mb-4" style="background-color:#ffd88e3b;">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <h6 class="mb-2 mt-1 fw-medium text-dark fs-18">{{ __('Orders Notifications') }}</h6>
                                <p class="text-body fs-14 mb-0">{{ __('Order-related notifications from your merchants and suppliers.') }}</p>
                                <a href="{{ route('orders.in') }}" class="btn btn-warning btn-sm px-3 mt-2">{{ __('View Orders') }}</a>
                            </div>
                            @if (file_exists(public_path('rizz/images/extra/card/notification.gif')))
                            <div class="col-auto align-self-center">
                                <img src="{{ asset('rizz/images/extra/card/notification.gif') }}" alt="" height="90" class="rounded">
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

                @php
                    $grouped = collect($notifications)->groupBy(function ($n) {
                        $d = $n->created_at;
                        if ($d->isToday()) return 'today';
                        if ($d->isYesterday()) return 'yesterday';
                        return $d->format('Y-m-d');
                    });
                @endphp

                @foreach ($grouped as $dateKey => $items)
                <div class="card-body py-2 mb-2">
                    <h5 class="text-body m-0 d-inline-block">
                        @if($dateKey === 'today') {{ __('Today') }}
                        @elseif($dateKey === 'yesterday') {{ __('Yesterday') }}
                        @else {{ \Carbon\Carbon::parse($dateKey)->translatedFormat('d F Y') }}
                        @endif
                    </h5>
                    <span class="text-primary bg-primary-subtle py-0 px-1 rounded fw-medium d-inline-block ms-1">{{ count($items) }}</span>
                </div>

                @foreach ($items as $notification)
                @php
                    $customer = \App\Models\User::find($notification->data['customer_id'] ?? null);
                    $order = \App\Models\Order::find($notification->data['order_id'] ?? null);
                    $timeStr = $notification->created_at->format('h:i A');
                    $isUnread = $notification->unread();
                @endphp
                <div class="card mb-3 notification-row {{ $isUnread ? 'border-primary border-opacity-50' : '' }}" data-read="{{ $isUnread ? '0' : '1' }}" data-id="{{ $notification->id }}">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-10">
                                <a href="{{ $order ? route('orders.show', $order->code) : '#' }}" class="text-decoration-none text-dark">
                                    <div class="d-flex align-items-start">
                                        <div class="flex-shrink-0">
                                            <img src="{{ $customer ? user_photo($customer->id) : placeholder_image('avatar') }}" alt="" class="thumb-lg rounded-circle" style="width:48px;height:48px;object-fit:cover;" onerror="this.src='{{ placeholder_image('avatar') }}'">
                                        </div>
                                        <div class="flex-grow-1 ms-3 text-truncate">
                                            <h6 class="my-1 fw-medium text-dark fs-14">
                                                @if($order)
                                                    {{ __('New order') }} {{ $order->code }}
                                                    @if($customer) {{ __('from') }} {{ ucfirst($customer->name) }} @endif
                                                @else
                                                    {{ __('Order notification') }}
                                                @endif
                                                <small class="text-muted ps-2">{{ $timeStr }}</small>
                                            </h6>
                                            <p class="text-muted mb-0 text-wrap fs-13">
                                                @if($order && $customer)
                                                    {{ __('A new order has been placed. Click to view details.') }}
                                                @else
                                                    {{ __('Order notification.') }}
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-2 text-end align-self-center mt-2 mt-md-0">
                                @if($order)
                                <a href="{{ route('orders.show', $order->code) }}" class="btn btn-primary btn-sm px-3">{{ __('View') }}</a>
                                @endif
                                @if($isUnread)
                                <button type="button" class="btn btn-sm btn-light-secondary mark-as-read" data-id="{{ $notification->id }}" title="{{ __('Mark as read') }}">
                                    <i class="iconoir-check"></i>
                                </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
                @endforeach
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-body text-center py-5">
                <div class="flex-shrink-0 bg-primary-subtle text-primary thumb-lg rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;">
                    <i class="iconoir-bell fs-1"></i>
                </div>
                <h4 class="mb-2 text-dark">{{ __('No notifications') }}</h4>
                <p class="text-muted mb-4">{{ __('There are no order notifications.') }}</p>
                <a href="{{ route('orders.in') }}" class="btn btn-primary">{{ __('View Orders') }}</a>
            </div>
        </div>
    @endif

    <x-slot name="script">
    <script>
        (function() {
            var markUrl = "{{ route('notifications.mark') }}";
            var token = $('meta[name="csrf-token"]').attr('content');

            function sendMarkRequest(id) {
                return $.ajax(markUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token },
                    data: { _token: '{{ csrf_token() }}', id: id || null }
                });
            }

            $(document).on('click', '.mark-as-read', function(e) {
                e.preventDefault();
                var btn = $(this);
                var id = btn.data('id');
                var card = btn.closest('.notification-row');
                sendMarkRequest(id).done(function() {
                    card.attr('data-read', '1').removeClass('border-primary border-opacity-50');
                    btn.remove();
                });
            });

            $(document).on('click', '#mark-all', function(e) {
                e.preventDefault();
                sendMarkRequest().done(function() { location.reload(); });
            });

            $(document).on('click', '#kt_notifications_reload', function(e) {
                e.preventDefault();
                location.reload();
            });
        })();
    </script>
    </x-slot>
</x-default-layout>

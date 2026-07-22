<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @if(count($notifications) > 0)
        <div class="card shadow-sm">
            @include(config('settings.KT_THEME_LAYOUT_DIR').'.partials.toolbars.notifications.orders')

            <div class="card-body py-5">
                @include('partials._kt-table-wrap')
                <table class="table table-hover align-middle gs-0 gy-4 mb-0" id="kt_inbox_listing">
                    <thead class="bg-light-primary">
                        <tr class="text-start text-gray-700 fw-bold fs-7 text-uppercase gs-0">
                            <th class="w-25px ps-6 py-4 rounded-start">
                                <div class="form-check form-check-sm form-check-custom form-check-solid">
                                    <input class="form-check-input" type="checkbox" data-kt-check="true" data-kt-check-target="#kt_inbox_listing .form-check-input" value="1" />
                                </div>
                            </th>
                            <th class="py-4 min-w-60px">Action</th>
                            <th class="py-4 min-w-150px">Supplier</th>
                            <th class="py-4 min-w-200px">Order / Type</th>
                            <th class="py-4 min-w-120px text-end">Status</th>
                            <th class="py-4 pe-6 min-w-100px text-end rounded-end">Date</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700 fw-semibold">
                        @foreach ($notifications as $notification)
                        @php
                            $supplier = \App\Models\User::find($notification->data['supplier_id'] ?? null);
                            $order = \App\Models\Order::find($notification->data['order_id'] ?? null);
                            $alert = ['product' => 'primary', 'quotation' => 'primary', 'order' => 'danger', 'deal' => 'success'];
                            $typeKey = $notification->data['type'] ?? 'quotation';
                            $fromData = $notification->created_at;
                            $days = \Carbon\Carbon::now()->diffInDays($fromData);
                        @endphp
                        <tr class="notification-row border-bottom border-gray-200" data-read="{{ $notification->unread() ? '0' : '1' }}" @if($notification->unread()) style="background:#e8f5e9;" @endif>
                            <td class="ps-6 py-4">
                                <div class="form-check form-check-sm form-check-custom form-check-solid">
                                    <input class="form-check-input row-checkbox" type="checkbox" value="{{ $notification->id }}" />
                                </div>
                            </td>
                            <td class="py-4">
                                @if($notification->unread())
                                    <a href="#" class="btn btn-icon btn-sm btn-light-primary mark-as-read" data-id="{{ $notification->id }}" data-bs-toggle="tooltip" title="Mark as read">
                                        <i class="bi bi-check2-all fs-4"></i>
                                    </a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="py-4">
                                @if($supplier && optional($supplier->profile)->username)
                                    <a href="{{ route('profile.show', $supplier->profile->username) }}" target="_blank" class="d-flex align-items-center text-gray-800 text-hover-primary">
                                        <div class="symbol symbol-35px symbol-circle me-3">
                                            <img src="{{ user_photo($supplier->id) }}" alt="{{ $supplier->name }}" class="w-100" onerror="this.src='{{ placeholder_image('avatar') }}'" />
                                        </div>
                                        <span class="fw-semibold">{{ ucfirst($supplier->name) }}</span>
                                    </a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="py-4">
                                @if($order)
                                    <a href="{{ route('orders.show', $order->code) }}" target="_blank" class="text-gray-800 text-hover-primary fw-bold d-block">{{ $order->code }}</a>
                                    <span class="text-muted fs-7">{{ $order->created_at ? $order->created_at->format('d M, Y') : '—' }}</span>
                                    <div class="mt-1">
                                        <span class="badge badge-light-{{ $alert[$typeKey] ?? 'primary' }} fs-8">{{ ucfirst($typeKey) }} {{ ($notification->data['action'] ?? 'created') }}d</span>
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="py-4 text-end">
                                @if($notification->unread())
                                    <span class="badge badge-light-success fs-7">New</span>
                                @else
                                    <span class="badge badge-light-info fs-7">Seen</span>
                                @endif
                            </td>
                            <td class="py-4 pe-6 text-end">
                                @if($days < 1)
                                    <span class="badge badge-light fw-semibold">Today</span>
                                @elseif($days == 1)
                                    <span class="badge badge-light fw-semibold">1 day ago</span>
                                @else
                                    <span class="badge badge-light fw-semibold">{{ $days }} days ago</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-dismissible bg-light-danger d-flex flex-column flex-sm-row w-100 p-5 mb-10 border-0">
            <i class="bi bi-inbox fs-2hx text-danger me-4 mb-3 mb-sm-0"></i>
            <div class="d-flex flex-column pe-0 pe-sm-10">
                <h4 class="mb-2 text-gray-800">No notifications</h4>
                <span class="text-gray-600">There are no quotation notifications.</span>
            </div>
            <a href="{{ route('orders.out') }}" class="btn btn-sm btn-danger ms-sm-auto mt-3 mt-sm-0">View Orders</a>
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
                sendMarkRequest(id).done(function() {
                    btn.closest('tr').attr('data-read', '1').css('background', '').find('.badge-success').replaceWith('<span class="badge badge-light-info">Seen</span>');
                    btn.replaceWith('<span class="text-muted">—</span>');
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

            $(document).on('click', '[data-kt-inbox-listing-filter]', function(e) {
                e.preventDefault();
                var filter = $(this).data('kt-inbox-listing-filter');
                var rows = $('#kt_inbox_listing tbody tr');
                rows.show();
                if (filter === 'show_unread') rows.filter('[data-read="1"]').hide();
                else if (filter === 'show_read') rows.filter('[data-read="0"]').hide();
            });

            $(document).on('click', '[data-kt-inbox-listing-filter="filter_newest"], [data-kt-inbox-listing-filter="filter_oldest"]', function(e) {
                e.preventDefault();
                var sort = $(this).data('kt-inbox-listing-filter');
                var tbody = $('#kt_inbox_listing tbody');
                var rows = tbody.find('tr').get();
                if (sort === 'filter_oldest') rows.reverse();
                $.each(rows, function(i, row) { tbody.append(row); });
            });
        })();
    </script>
    </x-slot>
</x-default-layout>

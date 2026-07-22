<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
	<!--begin::Card-->
	<div class="card">
		@include(config('settings.KT_THEME_LAYOUT_DIR').'.partials.toolbars.orders.index')

		<div class="card-header border-0 pt-6">
			<!--begin::Repeater-->
			<div id="kt_docs_repeater_basic">
				<!--begin::Form group-->
				<div class="form-group">
					<div class="form-group row">
						<div class="col"><h1 class="mt-3" style="color:green;">Transactions history between <b>{{ $supplier_user->name }}</b> and <b>{{ $customer_user->name }}</b></h1></div>
					</div>
				</div>
				<!--end::Form group-->

				<hr>
			</div>
			<!--end::Repeater-->
		</div>
		
		<!--begin::Card body-->
		<div class="card-body py-4">
			<!--begin::Table-->
			<table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4" id="kt_table_orders">
				<!--begin::Table head-->
				<thead>
				<!--begin::Table row-->
				<tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
					<th class="min-w-125px">Orders</th>
					<th class="min-w-125px">Amount</th>
					<th class="min-w-125px">Transfer Method</th>
					<th class="min-w-125px">Image</th>
					<th class="text-end min-w-100px">Notes</th>
					<th class="text-end min-w-100px">Date</th>
				</tr>
				<!--end::Table row-->
				</thead>
				<!--end::Table head-->

				<!--begin::Table body-->
				<tbody class="text-gray-600 fw-semibold">
					@foreach($transactions as $transaction)
                        @php
                            $orders = unserialize($transaction->orders);
                        @endphp
                        
						<!--begin::Table row-->
						<tr>
							<td>
								@if(!empty($orders))
									@foreach($orders as $order)
										@php
										$order = App\Models\Order::find($order['order_id']);
										@endphp

										<a href="{{ route('orders.show', $order->code) }}" target="_blank" class="btn btn-primary">{{ $order->code }}</a>
									@endforeach
								@endif
							</td>
							<td><b>{{ formatNumber($transaction->amount, 2) }}</b></td>
							<td><b>{{ $transaction->transfer_method }}</b></td>
							<td>
								@if(!empty($transaction->image))
                                <div class="cursor-pointer symbol symbol-35px symbol-md-90px">
                                    <img src="{{ asset('/storage/uploads/transactions/'.$transaction->image) }}" alt="" />
                                </div>
								@endif
                            </td>
							<td><b>{{ $transaction->notes }}</b></td>
							<td><b>{{ date("d F, Y (l)", strtotime($transaction->created_at)) }}</b></td>
						</tr>
						<!--end::Table row-->
					@endforeach
				</tbody>
				<!--end::Table body-->
			</table>
			<!--end::Table-->
		</div>
		<!--end::Card body-->
	</div>
	<!--end::Card-->
</x-default-layout>
<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
	<!--begin::Card-->
	<div class="card">
		@include(config('settings.KT_THEME_LAYOUT_DIR').'.partials.toolbars.orders.index')

		@if(session()->has('error'))
			<div class="alert alert-danger">
			{{session()->get('error')}}
			</div>
		@endif

		@if($errors->any())
			<div class="alert alert-danger">
				{{$errors->first() }}
			</div>
		@endif

		@if ($message = Session::get('success'))
			<div class="alert alert-success">
				<p>{{ $message }}</p>
			</div>
		@endif
			
		<div class="card-header border-0 pt-6">
			<!--begin::Repeater-->
			<div id="kt_docs_repeater_basic">
				@if($customer_check == true) 
				<!--begin::Form group-->
				<div class="form-group">
					<div data-repeater-list="kt_docs_repeater_basic">
						<div data-repeater-item>
							<div class="form-group row">
								<h3 class="page-heading d-flex text-dark fw-bold fs-3 flex-column justify-content-center my-0">Start making payments</h3>
								
								<br><br>

								<form action="{{ route('balance.post.transaction', encrypt($supplier_username)) }}" method="POST" enctype="multipart/form-data" id="post-transaction">
									@csrf

									<div class="col-md-2">
										<label class="form-label required">Amount:</label>

										<input type="text" readonly="true" name="amount" style="background: #dedede78;font-weight:bold;" class="form-control mb-2 mb-md-0"  id="amount" placeholder="Paid Amount" required />
									</div>

									<div class="col-md-2">
										<label class="form-label required">Total:</label>

										<input type="text" readonly="true" style="background: #dedede78;font-weight:bold;" class="form-control mb-2 mb-md-0" id="sum_total_balances" placeholder="Total Balance" value="{{ ($sum_total_balances - $total_transactions) }}" data-source="{{ ($sum_total_balances - $total_transactions) }}" required />
									</div>

									<div class="col-md-2">
										<label class="form-label required">Transfer Method:</label>
										
										<select name="transfer_method" id="transfer_method" class="form-select" aria-label="Please Select" required>
											<option value="">-- Please Select --</option>
											<option value="Credit card">Credit card</option>
											<option value="Brank Transfer">Brank Transfer</option>
											<option value="Paypal">Paypal</option>
											<option value="Western Union">Western Union</option>
											<option value="Money Gram">Money Gram</option>
										</select>
									</div>

									<div class="col-md-2">
										<label class="form-label">Conversion image:</label>
										
										<input type="file" name="image" class="form-control mb-2 mb-md-0" />
									</div>

									<div class="col-md-2">
										<label class="form-label">Notes:</label>

										<input type="text" name="notes" class="form-control mb-2 mb-md-0" placeholder="Notes" />
									</div>

									<div class="col-md-2">
										<button type="submit" class="btn btn-lg btn-light-primary mt-3 mt-md-8">
											<i class="ki-duotone ki-trash fs-5"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
											
											Pay Now
										</button>
									</div>

									<div id="orders"></div>
								</form>
							</div>
						</div>
					</div>
				</div>
				<!--end::Form group-->

				<br><hr>
				@endif

				<!--begin::Form group-->
				<div class="form-group">
					<div class="form-group row">
						<div class="col"><h1 class="mt-3" style="color:red;">Total Unpaid: {{ ($sum_total_balances - $total_transactions) }}</h1></div>
						<div class="col"><h1 class="mt-3" style="color:green;">Total Paid: {{ $total_transactions }}</h1></div>
						<div class="col">
							<form action="{{ route('balance.get.transactions') }}" method="POST">
								@csrf

								<input type="hidden" name="supplier" value="{{ encrypt($supplier) }}">
								<input type="hidden" name="customer" value="{{ encrypt($customer) }}">

								<button type="submit" class="btn btn-lg btn-primary">
									<i class="ki-duotone ki-trash fs-5"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
									
									Transactions History
								</button>
							</form>
						</div>
					</div>
				</div>
				<!--end::Form group-->

				<hr>
			</div>
			<!--end::Repeater-->
		</div>
		
		<!--begin::Card body-->
		<div class="card-body py-4">
			@if(session()->has('error'))
				<div class="alert alert-danger">
				{{session()->get('error')}}
				</div>
			@endif

			@if($errors->any())
				<div class="alert alert-danger">
					{{$errors->first() }}
				</div>
			@endif

			@if ($message = Session::get('success'))
				<div class="alert alert-success">
					<p>{{ $message }}</p>
				</div>
			@endif 

			<!--begin::Table-->
			<table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4" id="kt_table_orders">
				<!--begin::Table head-->
				<thead>
				<!--begin::Table row-->
				<tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
					<th class="w-10px pe-2"></th>
					<th class="min-w-125px">@if($customer_check == true) Supplier @else Customer @endif Name</th>
					<th class="min-w-125px">Order Code</th>
					<th class="min-w-125px">Order Image</th>
					<th class="min-w-125px">Price</th>
					<th class="min-w-125px">Quantity</th>
					<th class="min-w-125px">Product</th>
					<th class="min-w-125px">Delivery Date</th>
					<th class="min-w-125px">Notes</th>
				</tr>
				<!--end::Table row-->
				</thead>
				<!--end::Table head-->

				<!--begin::Table body-->
				<tbody class="text-gray-600 fw-semibold" id="prices">
					@foreach($balances as $balance)
						@php
							if($customer_check == true) {
							$user		= App\Models\User::find($balance->supplier_user_id);
							}
							else {
							$user	 	= App\Models\User::find($balance->customer_user_id);	
							}
							$profile    = App\Models\Profile::where('user_id',$user->id)->first();
							$order      = App\Models\Order::find($balance->order_id);
						@endphp

						<!--begin::Table row-->
						<tr {{ in_array($order->id, $order_ids) ? 'style=background:#c0d1c0b0;color:#333;border:1px dashed #333;' : '' }}>
							<!--begin::Checkbox-->
							<td>
								@if($customer_check == true) 
								<div class="form-check form-check-sm form-check-custom form-check-solid">
									<input class="form-check-input chkNumber" {{ in_array($order->id, $order_ids) ? 'disabled' : '' }} type="checkbox" name="orders[{{ $order->code }}]" value="{{ $balance->price }}" data-price="{{ $balance->price }}" />
								</div>
								@endif
							</td>
							<!--end::Checkbox-->
							<td><b><a href="{{ route('profile.show', $profile->username) }}" target="_blank">{{ $user->name }}</a></b></td>
							<td><a href="{{ route('orders.show', $order->code) }}" target="_blank" class="btn btn-primary">{{ $order->code }}</a> @if(!empty($order->ref_number))<br /><br />Ref. Num {{ $order->ref_number }}@endif</td>
							<td>
								<div class="cursor-pointer symbol symbol-35px symbol-md-90px">
									<a href="{{ $order->image ? asset('/storage/uploads/orders/original/'.$order->image) : order_image($order) }}" target="_blank">
										<img src="{{ order_image($order) }}" alt="" style="background:#dedede;padding:10px;border-radius:5px;display:block;" onerror="this.onerror=null; this.src='{{ placeholder_image('order') }}';">
									</a>
								</div>
							</td>
							<td><b>{{ formatNumber($balance->price, 2) }}</b></td>
							<td><b>{{ formatNumber($order->quantity) }}</b></td>
							<td><b><a href="{{ route('products.show', encrypt($order->product->id)) }}" target="_blank" class="btn btn-primary">{{ $order->product->name }}</a></b></td>
							<td><b>{{ $balance->delivery_date }}</b></td>
							<td><b>{{ $balance->notes }}</b></td>
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

	@section('script')
		<script type="text/javascript">
			$(function () {
				$('#post-transaction').on('submit', function(event){
                    event.preventDefault();
					
					var amount = $('#amount').val();

					if (amount == null || amount == "") {
						Swal.fire({
							text: "Sorry you must check on the amounts that you are willing to pay",
							icon: "error",
							buttonsStyling: false,
							confirmButtonText: "Ok, got it!",
							customClass: {
								confirmButton: "btn btn-primary"
							}
						});

						return false;
					}

					if (isNaN(amount)) {
						Swal.fire({
							text: "Sorry but amount value must be a valid number.",
							icon: "error",
							buttonsStyling: false,
							confirmButtonText: "Ok, got it!",
							customClass: {
								confirmButton: "btn btn-primary"
							}
						});

						$('#amount').val("");

						return false;
					}

					if (amount == 0) {
						Swal.fire({
							text: "Sorry but you can't make a transaction with zero value.",
							icon: "error",
							buttonsStyling: false,
							confirmButtonText: "Ok, got it!",
							customClass: {
								confirmButton: "btn btn-primary"
							}
						});

						return false;
					}

					if (amount < 50) {
						Swal.fire({
							text: "Sorry but you can't make a transaction with value less than 50.",
							icon: "error",
							buttonsStyling: false,
							confirmButtonText: "Ok, got it!",
							customClass: {
								confirmButton: "btn btn-primary"
							}
						});
						
						return false;
					}

					var transfer_method = $('#transfer_method').val();

					if (transfer_method === '') {
						Swal.fire({
							text: "Sorry you must select a transfer method first",
							icon: "error",
							buttonsStyling: false,
							confirmButtonText: "Ok, got it!",
							customClass: {
								confirmButton: "btn btn-primary"
							}
						});

						return false;
					}
					
					$.ajax({
						url			: $('#post-transaction').attr('action'),
                        method		: 'POST',
                        data: new FormData(this),
						processData: false,     
						contentType: false,    
						cache: false,
                        success		: function(response) {
							if(response.code == 200) {
								Swal.fire({
									text: response.message,
									icon: response.status,
									buttonsStyling: false,
									confirmButtonText: "Ok, got it!",
									customClass: {
										confirmButton: "btn btn-primary"
									}
								});

								setTimeout(function() {
									location.reload();
								}, 2000);
							}
							else {
								Swal.fire({
									text: "Something went wrong please contact the system administrator.",
									icon: "error",
									buttonsStyling: false,
									confirmButtonText: "Ok, got it!",
									customClass: {
										confirmButton: "btn btn-primary"
									}
								});
							}
						}
                    });
                });
			});

			const amount = document.getElementById('amount');

			const totalPrice = () => [...document.querySelectorAll('#prices input[type=checkbox]:checked')]
			.reduce((acc, {
				dataset: {
					price
				}
			}) => acc + +price, 0);

			document.getElementById('prices').addEventListener('change', () => amount.value  = totalPrice());

			checkBox = document.getElementById('prices').addEventListener('click', event => {
				jQuery('#orders').append("<input type='hidden' name=\"" + event.target.name + "\" value=\"" + event.target.value + "\">");

				var price = event.target.value;
				var amount = $('#amount').val();
				var sum_total_balances = $('#sum_total_balances').val();

				if(event.target.checked) {
					console.log('========================');
					console.log('Sum total balances : ' + sum_total_balances + ' --- ' +parseInt(sum_total_balances));
					console.log('Price : ' + price + ' --- ' + parseInt(price));
					console.log('--------------');
					console.log('Total : ' + (parseInt(sum_total_balances) - parseInt(price)));
					$('#sum_total_balances').val(parseInt(sum_total_balances) - parseInt(price));
				}
				else {
					jQuery('input[type="hidden"][name="' + event.target.name + '"]').remove();

					console.log('========================');
					console.log('Sum total balances : ' + sum_total_balances + ' --- ' +parseInt(sum_total_balances));
					console.log('Price : ' + price + ' --- ' + parseInt(price));
					console.log('--------------');
					console.log('Total : ' + parseInt(sum_total_balances) + parseInt(price));
					$('#sum_total_balances').val(parseInt(sum_total_balances) + parseInt(price));
				}
			});
		</script>
	@endsection
</x-default-layout>
<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
	<div class="card shadow-sm">
		<div class="card-header">
			<div class="row align-items-center">
				<div class="col">
					<h4 class="card-title">{{ __('Product details') }}</h4>
					<p class="mb-0 text-muted small">{{ $product->name }}</p>
				</div>
				<div class="col-auto">
					<a href="{{ route('products.index') }}" class="btn btn-light btn-sm me-1">
						<i class="las la-arrow-left me-1"></i> {{ __('Back') }}
					</a>
					@can('products-edit')
						<a href="{{ route('products.edit', encrypt($product->id)) }}" class="btn btn-primary btn-sm me-1">
							<i class="las la-pen me-1"></i> {{ __('Edit') }}
						</a>
					@endcan
					@can('products-delete')
						<form action="{{ route('products.destroy', encrypt($product->id)) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Are you sure you want to delete this product?') }}">
							@csrf
							@method('DELETE')
							<button type="submit" class="btn btn-danger btn-sm"><i class="las la-trash-alt me-1"></i> {{ __('Delete') }}</button>
						</form>
					@endcan
				</div>
			</div>
		</div>

		<div class="card-body pt-2">
			<div class="row g-5">
				<div class="col-lg-8">
					<div class="card card-bordered mb-5">
						<div class="card-header bg-light">
							<h3 class="card-title fs-5 fw-bold mb-0">Details</h3>
						</div>
						<div class="card-body">
							<div class="row g-4">
								<div class="col-12">
									<div class="text-muted small mb-1">{{ __('Name') }}</div>
									<div class="fw-bold fs-5">{{ $product->name }}</div>
								</div>
								@if($product->category)
								<div class="col-sm-6">
									<div class="text-muted small mb-1">{{ __('Category') }}</div>
									<a href="{{ route('categories.edit', $product->category->id) }}" target="_blank" class="text-primary text-decoration-none fw-semibold">{{ $product->category->name }}</a>
								</div>
								@endif
								@if(isset($product->price) && $product->price !== null && $product->price !== '')
								<div class="col-sm-6">
									<div class="text-muted small mb-1">{{ __('Price') }}</div>
									<div class="fw-semibold">{{ formatNumber($product->price, 2) }}</div>
								</div>
								@endif
							</div>
						</div>
					</div>

					@if($product->description)
					<div class="card card-bordered mb-5">
						<div class="card-header bg-light">
							<h3 class="card-title fs-5 fw-bold mb-0">Description</h3>
						</div>
						<div class="card-body">
							<div class="text-body">{{ $product->description }}</div>
						</div>
					</div>
					@endif

					@if(count($attributes) > 0)
					<div class="card card-bordered mb-5">
						<div class="card-header bg-light">
							<h3 class="card-title fs-5 fw-bold mb-0">Attributes</h3>
						</div>
						<div class="card-body">
							<div class="d-flex flex-column gap-3">
								@foreach($attributes as $attribute)
									@php $attribute_values = \App\Models\AttributeValues::where('attribute_id', $attribute->id)->get(); @endphp
									<div>
										<div class="text-muted small mb-1">{{ $attribute->name }}</div>
										@if($attribute_values->isNotEmpty())
											<div class="d-flex flex-wrap gap-2">
												@foreach($attribute_values as $attribute_value)
													<span class="badge bg-primary-subtle text-primary">{{ $attribute_value->value }}</span>
												@endforeach
											</div>
										@else
											<span class="text-muted">—</span>
										@endif
									</div>
								@endforeach
							</div>
						</div>
					</div>
					@endif
				</div>

				<div class="col-lg-4">
					<div class="card card-bordered mb-5">
						<div class="card-header bg-light">
							<h3 class="card-title fs-5 fw-bold mb-0">Image</h3>
						</div>
						<div class="card-body text-center p-4">
							@php
								$imageUrl = product_image($product, 'thumbnails');
								$imageLinkUrl = $product->image ? (str_starts_with($product->image, 'http') ? $product->image : asset('/storage/uploads/products/original/'.$product->image)) : $imageUrl;
							@endphp
							<a href="{{ $imageLinkUrl }}" target="_blank" class="d-inline-block">
								<img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="rounded shadow-sm" style="max-width:100%; height:auto; max-height:320px; object-fit:contain;" onerror="this.onerror=null; this.src='{{ placeholder_image('product') }}';">
							</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
        </div>
    </div>
</x-default-layout>

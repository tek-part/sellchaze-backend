<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Create Ticket') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Order') }} {{ $order->code }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('orders.show', $order->code) }}" class="btn btn-light btn-sm">
                                <i class="las la-arrow-left me-1"></i> {{ __('Back') }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if ($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <ul class="mb-0 ps-3">@foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <form action="{{ route('tickets.store') }}" method="POST">
                        @csrf
                        <input type="hidden" name="order_id" value="{{ $order->id }}">
                        <div class="row g-4">
                            <div class="col-md-6 col-lg-4">
                                <label for="type" class="form-label fw-semibold">{{ __('Type') }} <span class="text-danger">*</span></label>
                                <select name="type" id="type" class="form-select" required>
                                    <option value="replacement" {{ old('type') === 'replacement' ? 'selected' : '' }}>{{ __('Replacement') }}</option>
                                    <option value="return" {{ old('type') === 'return' ? 'selected' : '' }}>{{ __('Return') }}</option>
                                    <option value="other" {{ old('type') === 'other' ? 'selected' : '' }}>{{ __('Other') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label for="notes" class="form-label fw-semibold">{{ __('Notes') }} <span class="text-danger">*</span></label>
                            <textarea name="notes" id="notes" class="form-control" rows="4" required placeholder="{{ __('Describe the issue and required action...') }}">{{ old('notes') }}</textarea>
                        </div>
                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ __('Create Ticket') }}
                            </button>
                            <a href="{{ route('orders.show', $order->code) }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>

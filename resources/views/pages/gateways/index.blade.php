<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <x-crm.listing-wrap>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
            <p class="text-muted mb-0">{{ __('Gateway balances and wallets.') }}</p>
            @can('gateways-create')
                <a href="{{ route('gateways.create') }}" class="btn btn-primary btn-sm">
                    <i class="iconoir-plus me-1"></i> {{ __('Add Gateway') }}
                </a>
            @endcan
        </div>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show mb-4">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        @if($gateways->isEmpty())
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="iconoir-wallet fs-1 text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-0">{{ __('No gateways configured.') }}</p>
                </div>
            </div>
        @else
            <div class="row g-4">
                @foreach ($gateways as $gateway)
                    <div class="col-sm-6 col-xl-4">
                        <div class="card border shadow-sm h-100 gateway-card">
                            <div class="card-body">
                                <div class="d-flex align-items-start">
                                    <div class="flex-shrink-0">
                                        @if($gateway->logoPath())
                                            <div class="thumb-lg rounded-3 bg-light d-flex align-items-center justify-content-center overflow-hidden">
                                                <img src="{{ asset($gateway->logoPath()) }}" alt="{{ $gateway->name }}" class="gateway-logo" width="40" height="40">
                                            </div>
                                        @else
                                            <div class="thumb-lg rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle text-primary">
                                                <i class="{{ $gateway->iconClass() }} fs-2"></i>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="card-title fw-semibold mb-1">{{ $gateway->name }}</h5>
                                        <p class="text-muted fs-6 mb-2">{{ __('Balance') }}</p>
                                        <h4 class="fw-bold mb-3">{{ formatNumber($gateway->wallet?->balance ?? 0, 2) }}</h4>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <a href="{{ route('gateways.wallet', $gateway->slug) }}" class="btn btn-sm btn-primary">
                                                {{ __('View Wallet') }} <i class="iconoir-arrow-right ms-1 fs-6"></i>
                                            </a>
                                            @can('gateways-edit')
                                                <a href="{{ route('gateways.edit', $gateway->slug) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}">
                                                    <i class="las la-pen"></i>
                                                </a>
                                            @endcan
                                            @can('gateways-delete')
                                                <form action="{{ route('gateways.destroy', $gateway->slug) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Are you sure you want to delete this payment gateway?') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-light text-danger" title="{{ __('Delete') }}">
                                                        <i class="las la-trash-alt"></i>
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-crm.listing-wrap>

    @push('rizz-css')
    <style>
        .gateway-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .gateway-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,.1) !important;
        }
        .gateway-logo {
            object-fit: contain;
            border-radius: 0.5rem;
        }
    </style>
    @endpush
</x-default-layout>

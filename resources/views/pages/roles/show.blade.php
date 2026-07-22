<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Show Role') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Role details and assigned permissions.') }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('roles.index') }}" class="btn btn-light btn-sm">
                                <i class="las la-arrow-left me-1"></i> {{ __('Back to all roles') }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted">{{ __('Name') }}</label>
                            <div class="fw-bold fs-5">{{ $role->name }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted">{{ __('Permissions') }}</label>
                            <div class="d-flex flex-wrap gap-2">
                                @if(!empty($rolePermissions))
                                    @foreach($rolePermissions as $v)
                                        <span class="badge bg-primary-subtle text-primary">{{ $v->name }}</span>
                                    @endforeach
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>

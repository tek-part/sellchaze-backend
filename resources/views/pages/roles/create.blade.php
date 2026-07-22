<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Add New Role') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Create a new role and assign permissions.') }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('roles.index') }}" class="btn btn-light btn-sm">
                                <i class="las la-arrow-left me-1"></i> {{ __('Back') }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <ul class="mb-0 ps-3">@foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <form action="{{ route('roles.store') }}" method="POST">
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-6 col-lg-4">
                                <label for="name" class="form-label fw-semibold">{{ __('Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control" placeholder="{{ __('Role Name') }}" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">{{ __('Permissions') }}</label>
                                <div class="d-flex flex-wrap gap-3">
                                    @foreach($permission as $value)
                                        <div class="form-check">
                                            <input type="checkbox" name="permission[]" value="{{ $value->id }}" id="perm_{{ $value->id }}" class="form-check-input" />
                                            <label class="form-check-label" for="perm_{{ $value->id }}">{{ $value->name }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ __('Submit') }}
                            </button>
                            <a href="{{ route('roles.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>

<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12 col-lg-5 mb-4">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('Add routing') }}</h4>
                    <p class="text-muted small mb-0 mt-1">{{ __('Map a Wigpleasure store category ID to your accepted suppliers. You can add several suppliers for the same category; synced orders go to all mapped suppliers (that are accepted).') }}</p>
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif

                    @if(auth()->user()->hasRole('Merchant') && $partnerSuppliers->isEmpty())
                        <div class="alert alert-warning">{{ __('Accept supplier invitations first, then you can assign categories.') }}</div>
                    @endif

                    <form method="POST" action="{{ route('supplier-routings.store') }}">
                        @csrf
                        @if(auth()->user()->hasRole('Admin'))
                            <div class="mb-3">
                                <label class="form-label">{{ __('Merchant') }}</label>
                                <select name="merchant_id" class="form-select" required @disabled($merchants->isEmpty())>
                                    @foreach($merchants as $m)
                                        <option value="{{ $m->id }}" @selected(old('merchant_id') == $m->id)>{{ $m->name }} ({{ $m->email }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="mb-3">
                            <label class="form-label">{{ __('Wigpleasure category ID') }}</label>
                            <input type="number" name="wigpleasure_category_id" class="form-control" min="1" required value="{{ old('wigpleasure_category_id') }}" placeholder="e.g. 12">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Supplier') }}</label>
                            @if(auth()->user()->hasRole('Admin'))
                                <input type="number" name="supplier_user_id" class="form-control" min="1" required value="{{ old('supplier_user_id') }}" placeholder="{{ __('Supplier user ID') }}">
                                <p class="form-text small mb-0">{{ __('Must be an accepted supplier of the selected merchant (Invitations).') }}</p>
                            @else
                                <select name="supplier_user_id" class="form-select" required>
                                    <option value="">{{ __('Select supplier') }}</option>
                                    @foreach($partnerSuppliers as $s)
                                        <option value="{{ $s->id }}" @selected(old('supplier_user_id') == $s->id)>{{ $s->name }} ({{ $s->email }})</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                        @if(auth()->user()->hasRole('Admin') && $merchants->isNotEmpty())
                            <button type="submit" class="btn btn-primary">{{ __('Save routing') }}</button>
                        @elseif(auth()->user()->hasRole('Merchant') && $partnerSuppliers->isNotEmpty())
                            <button type="submit" class="btn btn-primary">{{ __('Save routing') }}</button>
                        @endif
                    </form>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('Current routings') }}</h4>
                </div>
                <div class="card-body pt-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    @if(auth()->user()->hasRole('Admin'))
                                        <th>{{ __('Merchant') }}</th>
                                    @endif
                                    <th>{{ __('Wigpleasure category ID') }}</th>
                                    <th>{{ __('Supplier') }}</th>
                                    <th class="text-end">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($routings as $r)
                                    <tr>
                                        @if(auth()->user()->hasRole('Admin'))
                                            <td>{{ $r->merchant?->name ?? '—' }}</td>
                                        @endif
                                        <td><code>{{ $r->wigpleasure_category_id }}</code></td>
                                        <td>{{ $r->supplier?->name ?? '—' }}</td>
                                        <td class="text-end">
                                            <form action="{{ route('supplier-routings.destroy', $r) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Remove this routing?') }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Remove') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ auth()->user()->hasRole('Admin') ? 4 : 3 }}" class="text-muted text-center py-4">{{ __('No routings yet. Lines without a mapping use all accepted suppliers.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>

<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Add New Attribute') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Create a product attribute with values.') }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('attributes.index') }}" class="btn btn-light btn-sm">
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

                    <form action="{{ route('attributes.store') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-6 col-lg-4">
                                <label for="name" class="form-label fw-semibold">{{ __('Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control" placeholder="{{ __('Attribute Name') }}" required>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="type" class="form-label fw-semibold">{{ __('Type') }}</label>
                                <select name="type" id="type" class="form-select">
                                    <option value="text">Text</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="form-label fw-semibold">{{ __('Attribute Values') }}</label>
                            <div class="input_fields_wrap">
                                <div class="p-3 mb-2 bg-light rounded border">
                                    <label class="form-label small text-muted">{{ __('Value') }} 1</label>
                                    <input type="text" name="attribute_values[1][value]" class="form-control" placeholder="{{ __('Attribute Value') }}" id="value_1">
                                </div>
                            </div>
                            <button type="button" class="add_field_button btn btn-sm btn-light mt-2"><i class="las la-plus me-1"></i>{{ __('Add Value') }}</button>
                        </div>
                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ __('Submit') }}
                            </button>
                            <a href="{{ route('attributes.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @push('rizz-js')
    <script>
        $(function() {
            var max_fields = 10, x = 1, wrapper = $(".input_fields_wrap"), add_btn = $(".add_field_button");
            add_btn.on("click", function(e) {
                e.preventDefault();
                var v1 = $("#value_1").val();
                if (!v1 && x === 1) { if (typeof rizzToast === 'function') rizzToast('warning', "{{ __('Please enter the first attribute value before adding more.') }}"); else alert("{{ __('Please enter the first attribute value before adding more.') }}"); return; }
                if (x >= max_fields) return;
                x++;
                wrapper.append('<div class="p-3 mb-2 bg-light rounded border"><label class="form-label small text-muted">{{ __('Value') }} '+x+'</label><div class="d-flex gap-2 align-items-end"><input type="text" name="attribute_values['+x+'][value]" class="form-control" placeholder="{{ __('Attribute Value') }}" id="value_'+x+'"><a href="#" class="remove_field btn btn-sm btn-light-danger"><i class="las la-times"></i></a></div></div>');
            });
            wrapper.on("click", ".remove_field", function(e) { e.preventDefault(); $(this).closest(".p-3").remove(); x--; });
        });
    </script>
    @endpush
</x-default-layout>

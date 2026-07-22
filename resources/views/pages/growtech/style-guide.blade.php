@extends('layout.growtech.master')
@section('content')
<section class="hero">
    <div class="header-bg header-bg--v1"></div>
    <div class="container">
        <div class="hero__header" data-animation="slide-down">
            <h1>{{ __('Style Guide') }}</h1>
            <p class="text-l-regular">{{ __('Design system and component library.') }}</p>
        </div>
    </div>
</section>
<section class="py-5">
    <div class="container">
        <p class="text-m-regular">{{ __('Content placeholder for style guide.') }}</p>
    </div>
</section>
@endsection
@push('script')
<script src="{{ asset('growtech/assets/js/pages/style-guide.js') }}"></script>
@endpush

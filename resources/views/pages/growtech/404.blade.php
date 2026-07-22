@extends('layout.growtech.master')
@section('content')
<section class="landing">
    <div class="pattern"></div>
    <div class="container" data-animation="slide-down">
        <div class="not-found-img"></div>
        <div class="content">
            <div class="text-wrap">
                <h3>{{ __('Page Not Found') }}</h3>
                <p class="text-l-regular">{{ __('Sorry, the requested page is unavailable.') }}</p>
            </div>
            <a class="btn-wrap" href="{{ url('/') }}" style="width: 100%">
                <button class="btn btn--medium btn--primary" type="button" style="width: 100%">{{ __('Back to home') }}</button>
            </a>
        </div>
    </div>
</section>
@endsection

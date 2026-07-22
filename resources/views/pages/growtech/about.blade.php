@php $gt = asset('growtech/assets'); @endphp
@extends('layout.growtech.master')
@section('content')
<section class="hero">
    <div class="header-bg header-bg--v1"></div>
    <div class="ornament"></div>
    <div class="container">
        <div class="about-hero-text" data-animation="slide-down">
            <h1 class="about-hero-text__heading">{{ __('landing.why.title') }}</h1>
            <div class="about-hero-text__sub-heading">
                <p class="text-l-regular">{{ __('landing.hero.subtitle') }}</p>
            </div>
        </div>
        <div class="about-hero-image-wrap" data-animation="fade-in">
            <img src="{{ $gt }}/media/images/illustration/about-v1-hero-img.png" alt="" class="about-hero-image__img"/>
        </div>
    </div>
</section>
<section class="cta cta--v2">
    <div class="background"></div>
    <div class="pattern"></div>
    <div class="container container--cta-v2" data-animation="slide-up">
        <h2 class="cta-title cta-title--v2">{{ __('landing.cta.title') }}</h2>
        <p class="cta-para cta-para--v2 text-l-regular">{{ __('landing.cta.subtitle') }}</p>
        <a href="{{ route('login') }}" class="btn btn--medium btn--primary">{{ __('landing.cta.button') }}</a>
    </div>
</section>
@endsection
@push('script')
<script src="{{ asset('growtech/assets/plugins/retina.js') }}"></script>
<script src="{{ asset('growtech/assets/js/pages/about-v1.js') }}"></script>
@endpush

@php $gt = asset('growtech/assets'); @endphp
@extends('layout.growtech.master')
@section('content')
<section class="hero">
    <div class="header-bg header-bg--v1"></div>
    <div class="container">
        <div class="hero__header" data-animation="slide-down">
            <h1>{{ __('FAQ Details') }}</h1>
            <p class="text-l-regular">{{ __('Detailed answers to common questions.') }}</p>
        </div>
        <div class="hero__content">
            <div class="content-wrap">
                <div class="text-content-wrap" data-animation="slide-right">
                    <h3>{{ __('What is :name?', ['name' => config('app.name')]) }}</h3>
                    <p class="text-m-regular">{{ __('A B2B platform for orders and quotations.') }}</p>
                </div>
                <div class="content-more" data-animation="slide-up">
                    <h4>{{ __('Can\'t find the right answer?') }}</h4>
                    <p class="text-m-regular">{{ __('Go to our contact page and') }} <a href="{{ route('growtech.contact') }}" class="link link--default text-m-regular">{{ __('get in touch with us.') }}</a></p>
                </div>
            </div>
        </div>
    </div>
</section>
<section class="cta cta--v2">
    <div class="background"></div>
    <div class="pattern"></div>
    <div class="container container--cta-v2" data-animation="slide-up">
        <h2 class="cta-title cta-title--v2">{{ __('landing.cta.title') }}</h2>
        <p class="cta-para cta-para--v2 text-l-regular">{{ __('landing.cta.subtitle') }}</p>
        <a href="{{ route('growtech.contact') }}" class="btn btn--medium btn--primary">{{ __('Contact Us') }}</a>
    </div>
</section>
@endsection

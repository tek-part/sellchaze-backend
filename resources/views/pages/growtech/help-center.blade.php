@php $gt = asset('growtech/assets'); @endphp
@extends('layout.growtech.master')
@section('content')
<section class="hero">
    <div class="header-bg header-bg--v1"></div>
    <div class="container">
        <div class="hero__header" data-animation="slide-down">
            <h1>{{ __('Browse questions by category') }}</h1>
            <p class="text-l-regular">{{ __('Find answers and get help.') }}</p>
        </div>
        <div class="row g-1 hero__content">
            <div class="col-md-4 col-sm-6" data-animation="slide-right">
                <a href="{{ route('growtech.faq') }}" class="text-decoration-none text-reset">
                    <div class="hero-card">
                        <div class="hero-card-icon">
                            <div class="icon-large icon-large__box icon-large__box--g-blue">
                                <img src="{{ $gt }}/media/images/icons/compass-white.svg" alt=""/>
                            </div>
                        </div>
                        <div class="hero-card-text">
                            <h4>{{ __('Getting Started') }}</h4>
                            <p class="text-m-regular">{{ __('Learn the basics') }}</p>
                        </div>
                        <div class="hero-card-button">
                            <span class="btn btn--medium btn--primary">{{ __('Learn More') }}</span>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-sm-6" data-animation="slide-down">
                <a href="{{ route('growtech.faq') }}" class="text-decoration-none text-reset">
                    <div class="hero-card">
                        <div class="hero-card-icon">
                            <div class="icon-large icon-large__box icon-large__box--g-blue">
                                <img src="{{ $gt }}/media/images/icons/monitor-white.svg" alt=""/>
                            </div>
                        </div>
                        <div class="hero-card-text">
                            <h4>{{ __('Product Specs') }}</h4>
                            <p class="text-m-regular">{{ __('Technical details') }}</p>
                        </div>
                        <div class="hero-card-button">
                            <span class="btn btn--medium btn--primary">{{ __('Learn More') }}</span>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-sm-6" data-animation="slide-left">
                <a href="{{ route('growtech.contact') }}" class="text-decoration-none text-reset">
                    <div class="hero-card">
                        <div class="hero-card-icon">
                            <div class="icon-large icon-large__box icon-large__box--g-blue">
                                <img src="{{ $gt }}/media/images/icons/chat-white.svg" alt=""/>
                            </div>
                        </div>
                        <div class="hero-card-text">
                            <h4>{{ __('Contact Support') }}</h4>
                            <p class="text-m-regular">{{ __('Get in touch') }}</p>
                        </div>
                        <div class="hero-card-button">
                            <span class="btn btn--medium btn--primary">{{ __('Contact Us') }}</span>
                        </div>
                    </div>
                </a>
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

@extends('layout.growtech.master')
@section('content')
<section class="hero">
    <div class="header-bg header-bg--v1"></div>
    <div class="container">
        <div class="hero__header" data-animation="slide-down">
            <h1>{{ __('Getting Started') }}</h1>
            <p class="text-l-regular">{{ __('Frequently asked questions.') }}</p>
        </div>
        <div class="hero__content">
            <div class="content-card">
                <div class="col-12" data-animation="slide-right">
                    <div class="card-wrap">
                        <div class="card-text">
                            <h4>{{ __('How do I get started?') }}</h4>
                            <p class="text-m-regular">{{ __('Sign up for an account and complete your profile to get started.') }}</p>
                        </div>
                        <div class="card-link">
                            <a href="{{ route('growtech.contact') }}" class="link"><p class="link__text text-m-bold">{{ __('Read More') }}</p><div class="link__icon">&nbsp;</div></a>
                        </div>
                    </div>
                </div>
                <div class="col-12" data-animation="slide-left">
                    <div class="card-wrap">
                        <div class="card-text">
                            <h4>{{ __('How can I set up an account?') }}</h4>
                            <p class="text-m-regular">{{ __('Go to the sign up page and follow the registration process.') }}</p>
                        </div>
                        <div class="card-link">
                            <a href="{{ route('register.choose-type') }}" class="link"><p class="link__text text-m-bold">{{ __('Sign Up') }}</p><div class="link__icon">&nbsp;</div></a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="content-more" data-animation="slide-up">
                <h4>{{ __('Can\'t find the right answer?') }}</h4>
                <p class="text-m-regular">{{ __('Go to our contact page and') }} <a href="{{ route('growtech.contact') }}" class="link link--default text-m-regular">{{ __('get in touch with us.') }}</a> {{ __('Our support team is ready to help you.') }}</p>
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
        <a href="{{ route('register.choose-type') }}" class="btn btn--medium btn--primary">{{ __('landing.hero.cta') }}</a>
    </div>
</section>
@endsection

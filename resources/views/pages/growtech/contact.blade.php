@php $gt = asset('growtech/assets'); @endphp
@extends('layout.growtech.master')
@section('content')
<section class="hero">
    <div class="header-bg header-bg--v1"></div>
    <div class="container">
        <div class="hero__header" data-animation="slide-down">
            <h1>{{ __('Contact') }}</h1>
            <p class="text-l-regular">{{ __('Get in touch with us.') }}</p>
        </div>
        <div class="hero__form" data-animation="fade-in">
            <form action="#" method="post" class="form-wrap">
                @csrf
                <div class="form-line form-line-1">
                    <div class="form-group form-group__name">
                        <label for="name" class="items__label">{{ __('Name') }}</label>
                        <div class="items__inputs"><input name="name" id="name" class="items__inputs--input" placeholder="{{ __('Enter your name') }}"/></div>
                    </div>
                    <div class="form-group form-group__email">
                        <label for="email" class="items__label">{{ __('Email') }}</label>
                        <div class="items__inputs"><input type="email" name="email" id="email" class="items__inputs--input" placeholder="{{ __('Enter your email') }}"/></div>
                    </div>
                </div>
                <div class="form-line form-line-2">
                    <div class="form-group form-group__phone">
                        <label for="phone" class="items__label">{{ __('Phone') }}</label>
                        <div class="items__inputs"><input name="phone" id="phone" class="items__inputs--input" placeholder="(+12) 345 - 678"/></div>
                    </div>
                    <div class="form-group form-group__company">
                        <label for="company" class="items__label">{{ __('Company') }}</label>
                        <div class="items__inputs"><input name="company" id="company" class="items__inputs--input" placeholder="{{ __('Company Name') }}"/></div>
                    </div>
                </div>
                <div class="form-line form-line-3">
                    <div class="form-group form-group__message">
                        <label for="message" class="items__label">{{ __('Message') }}</label>
                        <div class="items__inputs-area">
                            <textarea name="message" id="message" class="items__inputs-area--textarea" rows="6" placeholder="{{ __('Your message...') }}"></textarea>
                        </div>
                    </div>
                </div>
                <div class="button-wrap">
                    <button type="submit" class="btn btn--medium btn--primary" style="width: 100%">{{ __('Send Message') }}</button>
                </div>
            </form>
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
<script src="{{ asset('growtech/assets/js/pages/contact.js') }}"></script>
@endpush

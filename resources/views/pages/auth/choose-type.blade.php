@php $gt = asset('growtech/assets'); @endphp
<x-auth-layout
    body-class="choose-type"
    page-css="choose-type.css"
    :form-title="__('Create Account')"
    :form-subtitle="__('Choose your account type to get started.')"
>
    <div class="choose-type__cards">
        <a href="{{ route('register', ['type' => 'merchant']) }}" class="choose-type__card">
            <div class="choose-type__card__icon choose-type__card__icon--merchant">
                <img src="{{ $gt }}/media/images/icons/pie-chart.svg" alt="">
            </div>
            <span class="choose-type__card__title">{{ __('Register as Merchant') }}</span>
            <p class="choose-type__card__desc">{{ __('Place orders and manage quotations from suppliers.') }}</p>
            <span class="choose-type__card__arrow">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
        </a>
        <a href="{{ route('register', ['type' => 'supplier']) }}" class="choose-type__card">
            <div class="choose-type__card__icon choose-type__card__icon--supplier">
                <img src="{{ $gt }}/media/images/icons/layers.svg" alt="">
            </div>
            <span class="choose-type__card__title">{{ __('Register as Supplier') }}</span>
            <p class="choose-type__card__desc">{{ __('Receive orders and send quotations to merchants.') }}</p>
            <span class="choose-type__card__arrow">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
        </a>
    </div>

    <div class="choose-type__footer text-m-regular">
        {{ __('Already have an Account?') }}
        <a href="{{ route('login') }}" class="link text-s-bold text-primary">{{ __('Sign In') }}</a>
    </div>
</x-auth-layout>

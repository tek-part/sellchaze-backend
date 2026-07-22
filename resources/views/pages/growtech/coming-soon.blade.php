@extends('layout.growtech.master')
@section('content')
<section class="landing">
    <div class="pattern"></div>
    <div class="container" data-animation="slide-down">
        <div class="coming-soon-img"></div>
        <div class="content">
            <p class="text-l-regular">{{ __('Growtech is coming soon. Fill in your email to receive a notification email when we launch.') }}</p>
            <form action="#" class="form-search cta-v2__form" method="post">
                @csrf
                <div class="form-search__input">
                    <input type="email" name="email" class="form-search__input--inp coming-soon-form" placeholder="{{ __('Enter your email') }}" required>
                </div>
                <button type="submit" class="form-search__btn btn btn--medium btn--primary">{{ __('Subscribe now') }}</button>
            </form>
        </div>
    </div>
</section>
@endsection

@php $gt = asset('growtech/assets'); @endphp
<x-landing-layout>
    {{-- Hero --}}
    <section class="home--v1">
        <div class="header-bg header-bg--v1"></div>
        <div class="home__title container" data-animation="slide-down">
            <h1>{{ __('landing.hero.title') }}</h1>
            <p>{{ __('landing.hero.subtitle') }}</p>
            <div class="home__button">
                @if (Route::has('register'))
                <a href="{{ route('register.choose-type') }}" class="btn btn--large btn--primary">{{ __('landing.hero.cta') }}</a>
                @else
                <a href="{{ route('requestInvitation') }}" class="btn btn--large btn--primary">{{ __('landing.hero.cta') }}</a>
                @endif
                <a href="{{ route('login') }}" class="btn btn--large btn--secondary-white">{{ __('Sign In') }}<div class="btn__icon"></div></a>
            </div>
        </div>
        <div class="home__ilustration container" data-animation="fade-in">
            <img src="{{ $gt }}/media/images/illustration/sellchase-dashboard.png" alt="{{ __('landing.hero.subtitle') }}" data-rjs="{{ $gt }}/media/images/illustration/sellchase-dashboard.png"/>
        </div>
    </section>

    {{-- Who it's for (SaaS roles) --}}
    <section class="home--why-growtech py-5">
        <h2 class="text-center mb-2" data-animation="slide-up">{{ __('landing.roles.title') }}</h2>
        <p class="text-center text-m-regular mb-4 container" data-animation="slide-up">{{ __('landing.roles.subtitle') }}</p>
        <div class="container">
            <div class="row g-4 justify-content-center">
                <div class="col-md-4" data-animation="slide-right">
                    <div class="home__content-why__details__detail h-100">
                        <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/layers.svg" alt=""/></div>
                        <h4>{{ __('landing.roles.admin.title') }}</h4>
                        <p class="text-m-regular mb-0">{{ __('landing.roles.admin.desc') }}</p>
                    </div>
                </div>
                <div class="col-md-4" data-animation="fade-in">
                    <div class="home__content-why__details__detail h-100">
                        <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/monitor-white.svg" alt=""/></div>
                        <h4>{{ __('landing.roles.merchant.title') }}</h4>
                        <p class="text-m-regular mb-0">{{ __('landing.roles.merchant.desc') }}</p>
                    </div>
                </div>
                <div class="col-md-4" data-animation="slide-left">
                    <div class="home__content-why__details__detail h-100">
                        <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/order-white.svg" alt=""/></div>
                        <h4>{{ __('landing.roles.supplier.title') }}</h4>
                        <p class="text-m-regular mb-0">{{ __('landing.roles.supplier.desc') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Why --}}
    <section class="home--why-growtech">
        <h2 data-animation="slide-up">{{ __('landing.why.title') }}</h2>
        <div class="home__content-why container">
            <div class="home__content-why__details" data-animation="slide-right">
                <div class="home__content-why__details__detail">
                    <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/layers.svg" alt=""/></div>
                    <h4>{{ __('landing.why.speed.title') }}</h4>
                    <p class="text-m-regular">{{ __('landing.why.speed.desc') }}</p>
                </div>
                <div class="home__content-why__details__detail">
                    <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/pie-chart.svg" alt=""/></div>
                    <h4>{{ __('landing.why.pro.title') }}</h4>
                    <p class="text-m-regular">{{ __('landing.why.pro.desc') }}</p>
                </div>
            </div>
            <div class="home__content-why__illustration" data-animation="fade-in">
                <img src="{{ $gt }}/media/images/illustration/illustration.svg" alt=""/>
            </div>
            <div class="home__content-why__details" data-animation="slide-left">
                <div class="home__content-why__details__detail">
                    <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/keypad.svg" alt=""/></div>
                    <h4>{{ __('landing.why.easy.title') }}</h4>
                    <p class="text-m-regular">{{ __('landing.why.easy.desc') }}</p>
                </div>
                <div class="home__content-why__details__detail">
                    <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/trending-up.svg" alt=""/></div>
                    <h4>{{ __('Balance') }}</h4>
                    <p class="text-m-regular">{{ __('landing.feature.balance') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section class="home--feature">
        <h2 data-animation="slide-down">{{ __('landing.features.title') }}</h2>
        <div class="home__container-feature container">
            <div class="home__feature">
                <div class="home__feature__detail" data-animation="slide-right">
                    <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/timer-white.svg" alt=""/></div>
                    <h3>{{ __('Orders') }}</h3>
                    <p class="text-m-regular">{{ __('landing.feature.orders') }}</p>
                    <a href="{{ route('login') }}" class="home__feature__detail__btn btn btn--medium btn--primary">{{ __('landing.cta.button') }}</a>
                </div>
                <div class="home__feature__illustration" data-animation="slide-left">
                    <img src="{{ $gt }}/media/images/illustration/illustration4.svg" alt=""/>
                </div>
            </div>
            <div class="home__feature">
                <div class="home__feature__illustration" data-animation="slide-right">
                    <img src="{{ $gt }}/media/images/illustration/illustration-rev.svg" alt=""/>
                </div>
                <div class="home__feature__detail" data-animation="slide-left">
                    <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/lock-white.svg" alt=""/></div>
                    <h3>{{ __('Quotations') }}</h3>
                    <p class="text-m-regular">{{ __('landing.feature.quotations') }}</p>
                    <a href="{{ route('login') }}" class="home__feature__detail__btn btn btn--medium btn--primary">{{ __('landing.cta.button') }}</a>
                </div>
            </div>
            <div class="home__feature">
                <div class="home__feature__detail" data-animation="slide-right">
                    <div class="icon-large icon-large__box icon-large__box--g-blue"><img src="{{ $gt }}/media/images/icons/monitor-white.svg" alt=""/></div>
                    <h3>{{ __('Deals') }}</h3>
                    <p class="text-m-regular">{{ __('landing.feature.deals') }}</p>
                    <a href="{{ route('login') }}" class="home__feature__detail__btn btn btn--medium btn--primary">{{ __('landing.cta.button') }}</a>
                </div>
                <div class="home__feature__illustration" data-animation="slide-left">
                    <img src="{{ $gt }}/media/images/illustration/illustration-rev2.svg" alt=""/>
                </div>
            </div>
        </div>
    </section>

    @if(Route::has('blog.index'))
    {{-- Articles --}}
    <section class="home--article">
        <div class="home__article">
            <h2 class="home__article__blue" data-animation="slide-down">{{ __('landing.blog.title') }}</h2>
            <div class="home__article__sosmed container">
                <div class="row d-flex justify-content-center gap-warp" data-animation="slide-up">
                    @forelse(($latestArticles ?? []) as $article)
                    <div class="col-lg-5 col-xl-4">
                        <div class="card card--article-type-5 mb-3">
                            @if($article->featured_image)
                            <img src="{{ asset('storage/' . $article->featured_image) }}" alt="{{ $article->title }}" class="card-img-top card-photo--type-5"/>
                            @else
                            <img src="{{ $gt }}/media/images/illustration/card-4.png" alt="{{ $article->title }}" class="card-img-top card-photo--type-5"/>
                            @endif
                            <div class="card-img-overlay"><p class="badge badge--primary-blue text-s-regular">{{ __('Articles') }}</p></div>
                            <div class="card-body card-body--type-5">
                                <a href="{{ route('blog.show', $article->slug) }}"><h4 class="card-title card-title--type-5">{{ $article->title }}</h4></a>
                                <p class="card-text card-text card-text--type-5 text-m-regular">{{ Str::limit($article->excerpt ?? $article->content, 80) }}</p>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="col-lg-5 col-xl-4">
                        <div class="card card--article-type-5 mb-3">
                            <img src="{{ $gt }}/media/images/illustration/card-4.png" alt="" class="card-img-top card-photo--type-5"/>
                            <div class="card-img-overlay"><p class="badge badge--primary-yellow text-s-regular">{{ __('Marketing') }}</p></div>
                            <div class="card-body card-body--type-5">
                                <a href="{{ route('blog.index') }}"><h4 class="card-title card-title--type-5">{{ __('landing.blog.empty') }}</h4></a>
                                <p class="card-text card-text card-text--type-5 text-m-regular">{{ __('landing.blog.subtitle') }}</p>
                            </div>
                        </div>
                    </div>
                    @endforelse
                </div>
            </div>
            <a href="{{ route('blog.index') }}" class="home__btn-2 btn btn--medium btn--primary">{{ __('landing.blog.cta') }}</a>
        </div>
    </section>
    @endif

    {{-- CTA --}}
    <section class="cta cta--v1">
        <div class="pattern"></div>
        <div class="container container--cta-v1" data-animation="slide-up">
            <h2 class="cta-title cta-title--v1">{{ __('landing.cta.title') }}</h2>
            <p class="cta-para cta-para--v1 text-l-regular">{{ __('landing.cta.subtitle') }}</p>
            <div class="cta-btn-container cta-btn-container--v1">
                @if (Route::has('register'))
                <a href="{{ route('register.choose-type') }}" class="btn btn--medium btn--primary">{{ __('landing.hero.cta') }}</a>
                @else
                <a href="{{ route('requestInvitation') }}" class="btn btn--medium btn--primary">{{ __('landing.hero.cta') }}</a>
                @endif
                <a href="{{ route('login') }}" class="btn btn--medium btn--secondary-purple">{{ __('landing.cta.button') }}</a>
            </div>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="footer footer--v1 footer-custom1">
        <div class="footer__container container">
            <div class="footer__sosmed">
                <h3 class="footer__sosmed__title">{{ $siteTitle ?? config('app.name', 'SELLCHASE') }}</h3>
                <p class="footer__sosmed__detail text-m-regular">{{ $siteDescription ?? __('landing.hero.subtitle') }}</p>
            </div>
            <div class="footer__links">
                <div class="footer__links__pages">
                    <span class="footer__links__pages__title text-m-bold">{{ __('Pages') }}</span>
                    <div class="footer__links__pages__links">
                        <a class="footer__links__pages__links__a" href="{{ url('/') }}">Home</a>
                        @if(Route::has('blog.index'))
                        <a class="footer__links__pages__links__a" href="{{ route('blog.index') }}">Blog</a>
                        @endif
                        <a class="footer__links__pages__links__a" href="{{ route('login') }}">{{ __('Sign In') }}</a>
                        @if (Route::has('register'))
                        <a class="footer__links__pages__links__a" href="{{ route('register.choose-type') }}">{{ __('Sign up') }}</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="footer__copyright container">
            <p>&copy; {{ date('Y') }} {{ $siteTitle ?? config('app.name') }}. {{ __('B2B orders and quotations') }}</p>
        </div>
    </footer>
    @push('script')
    <script src="{{ asset('growtech/assets/plugins/retina.js') }}"></script>
    <script src="{{ asset('growtech/assets/js/pages/home-v1.js') }}"></script>
@endpush
</x-landing-layout>

@php $gt = asset('growtech/assets'); @endphp
@extends('layout.growtech.master')
@section('content')
<section class="hero">
    <div class="header-bg header-bg--v1 header-bg--short"></div>
    <div class="container">
        <div class="hero__left" data-animation="slide-right">
            <div class="hero-text-container">
                <h1>{{ __('landing.blog.title') }}</h1>
                <p class="text-l-regular">{{ __('landing.blog.subtitle') }}</p>
            </div>
            <div class="hero-btn-wrap">
                <a href="{{ route('blog.index') }}" class="btn btn--large btn--primary">{{ __('landing.blog.cta') }}</a>
            </div>
        </div>
        <div class="hero__right" data-animation="slide-left">
            @if($articles->isNotEmpty() && $articles->first()->featured_image)
            <img src="{{ asset('storage/' . $articles->first()->featured_image) }}" alt=""/>
            @else
            <img src="{{ $gt }}/media/images/illustration/blog-v1-hero.png" alt=""/>
            @endif
            <p class="badge badge--primary-yellow text-s-regular">{{ __('Articles') }}</p>
        </div>
    </div>
</section>
<section class="articles-blog">
    <div class="pattern pattern--1"></div>
    <div class="container">
        <div class="header" data-animation="slide-down">
            <h2>{{ __('Latest Articles') }}</h2>
        </div>
        <div class="content" data-animation="slide-up">
            <div class="tab-content">
                <div class="tab-pane fade show active row">
                    @forelse($articles as $article)
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card card--article-type-6">
                            @if($article->featured_image)
                            <img src="{{ asset('storage/' . $article->featured_image) }}" alt="{{ $article->title }}" class="card-img-top card-photo card-photo--type-6"/>
                            @else
                            <img src="{{ $gt }}/media/images/illustration/blog-v1-card1.png" alt="{{ $article->title }}" class="card-img-top card-photo card-photo--type-6"/>
                            @endif
                            <div class="card-img-overlay"><p class="badge badge--primary-blue">{{ __('Articles') }}</p></div>
                            <div class="card-body card-body--type-6">
                                <a href="{{ route('blog.show', $article->slug) }}"><h4 class="card-title card-title--type-6">{{ $article->title }}</h4></a>
                                <p class="card-text card-text--type-6 text-m-regular">{{ Str::limit($article->excerpt ?? $article->content, 80) }}</p>
                                <a href="{{ route('blog.show', $article->slug) }}" class="link text-m-bold">{{ __('Read More') }}</a>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="col-12 text-center py-5">
                        <p class="text-l-regular">{{ __('landing.blog.empty') }}</p>
                        <a href="{{ url('/') }}" class="btn btn--medium btn--primary mt-3">{{ __('Back to Home') }}</a>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
        @if($articles->hasPages())
        <div class="btn-wrap mt-4">
            {{ $articles->links() }}
        </div>
        @endif
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
@push('script')
<script src="{{ asset('growtech/assets/js/pages/blog-v1.js') }}"></script>
@endpush

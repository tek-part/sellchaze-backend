@php $gt = asset('growtech/assets'); @endphp
@extends('layout.growtech.master')
@section('content')
<section class="hero">
    <div class="header-bg header-bg--v2"></div>
    <div class="container">
        <div class="hero-wrap">
            <div class="hero-header" data-animation="slide-down">
                <h1>{{ $article->title }}</h1>
                <p class="text-l-regular">
                    {{ $article->published_at?->format('F d, Y') }}
                    @if($article->author)
                    · {{ __('by') }} {{ $article->author->name }}
                    @endif
                </p>
            </div>
            <div class="hero-content">
                @if($article->featured_image)
                <img src="{{ asset('storage/' . $article->featured_image) }}" alt="{{ $article->title }}" class="hero-content__img"/>
                @else
                <img src="{{ $gt }}/media/images/illustration/blog-details-img-1.png" alt="{{ $article->title }}" class="hero-content__img"/>
                @endif
                <div class="content-inner">
                    <div class="content-inner-first content-blog" data-animation="slide-right">
                        @if($article->excerpt)
                        <p class="text-m-regular paragraph lead">{{ $article->excerpt }}</p>
                        @endif
                        <div class="text-m-regular paragraph">
                            {!! nl2br(e($article->content ?? '')) !!}
                        </div>
                    </div>
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
        <a href="{{ route('blog.index') }}" class="btn btn--medium btn--primary">{{ __('landing.blog.cta') }}</a>
    </div>
</section>
@endsection

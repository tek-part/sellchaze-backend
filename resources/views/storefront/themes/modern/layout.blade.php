{{--
    Theme 01 "Modern" — shared Blade LAYOUT. Reused by the section-rendered pages
    (render.blade.php) and by the transactional pages (pages/*.blade.php) so the
    header/announcement/overlays/footer + tokens are authored once.
    Expects: $context (store, navigation, theme.settings, seo). Optional flags:
    $chrome (default true) — show announcement/header/overlays/footer;
    $container (default true) — wrap @yield('content') in .mod-container.
--}}
@php
    $store = (object) $context['store'];
    $seo = $context['seo'] ?? [];
    $theme = $context['theme'] ?? [];
    $nav = $context['navigation'] ?? [];
    $s = $theme['settings'] ?? [];
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
    $announce = $s['announcement'] ?? ($context['data']['announcement'] ?? null);
    $header = $nav['header'] ?? [];
    $footer = $nav['footer'] ?? [];
    $chrome = $chrome ?? true;
    $container = $container ?? true;
@endphp<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] ?? $store->name }}</title>
    <meta name="description" content="{{ $seo['description'] ?? '' }}">
    <meta name="robots" content="{{ $seo['robots'] ?? 'index, follow' }}">
    <link rel="canonical" href="{{ $seo['canonical'] ?? '' }}">
    @foreach(($seo['og'] ?? []) as $property => $content)<meta property="{{ $property }}" content="{{ $content }}">@endforeach
    @foreach(($seo['twitter'] ?? []) as $name => $content)<meta name="{{ $name }}" content="{{ $content }}">@endforeach
    @if(!empty($seo['json_ld']))<script type="application/ld+json">{!! json_encode($seo['json_ld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>@endif
    @include('storefront.themes.modern._styles', ['s' => $s])
    @if(!empty($theme['custom_css']))<style data-store-custom-css>{!! $theme['custom_css'] !!}</style>@endif
    @if(!empty($theme['responsive_css']))<style data-responsive-section-css>{!! $theme['responsive_css'] !!}</style>@endif
</head>
<body id="storefront-root" data-rendered-by="blade" data-theme="{{ $theme['key'] ?? '' }}" data-theme-version="{{ $theme['version'] ?? '' }}">
    @if($chrome)
    <input id="mod-search" class="mod-toggle" type="checkbox" aria-hidden="true">
    <input id="mod-cart" class="mod-toggle" type="checkbox" aria-hidden="true">
    <input id="mod-menu" class="mod-toggle" type="checkbox" aria-hidden="true">

    @if($announce)<div class="mod-announce">{{ $announce }}</div>@endif

    <header class="mod-header">
        <div class="mod-container mod-header__row">
            <a class="mod-brand" href="/">{{ $store->name }}</a>
            <nav class="mod-nav" aria-label="primary">
                @forelse($header as $it)<a href="{{ $it['url'] }}">{{ $it['label'] }}</a>@empty<a href="/">Home</a><a href="/products">Products</a>@endforelse
            </nav>
            <div class="mod-header__spacer"></div>
            <div class="mod-header__actions">
                <label class="mod-iconbtn" for="mod-search" aria-label="Search">⌕</label>
                <a class="mod-iconbtn" href="/wishlist" aria-label="Wishlist">♡</a>
                <label class="mod-iconbtn" for="mod-cart" aria-label="Cart">🛍</label>
                <label class="mod-iconbtn mod-only-mobile" for="mod-menu" aria-label="Menu">☰</label>
            </div>
        </div>
    </header>

    <div class="mod-overlay mod-search-overlay" role="dialog" aria-label="Search">
        <label class="mod-overlay__scrim" for="mod-search" aria-label="Close"></label>
        <form class="mod-search-box" action="/search" method="get">
            <input class="mod-input" type="search" name="q" placeholder="ابحث عن منتج…" aria-label="Search">
            <button class="mod-btn" type="submit">بحث</button>
        </form>
    </div>

    <aside class="mod-drawer mod-cart-drawer" role="dialog" aria-label="Cart">
        <label class="mod-drawer__scrim" for="mod-cart" aria-label="Close"></label>
        <div class="mod-drawer__panel">
            <div class="mod-drawer__head"><strong>سلة التسوق</strong><label class="mod-iconbtn" for="mod-cart" aria-label="Close">✕</label></div>
            <div class="mod-drawer__body"><p class="mod-empty">سلتك فارغة حالياً.</p></div>
            <a class="mod-btn" href="/cart">عرض السلة</a>
        </div>
    </aside>

    <aside class="mod-drawer mod-mobile-nav" role="dialog" aria-label="Menu">
        <label class="mod-drawer__scrim" for="mod-menu" aria-label="Close"></label>
        <nav class="mod-drawer__panel">
            @forelse($header as $it)<a href="{{ $it['url'] }}">{{ $it['label'] }}</a>@empty<a href="/">Home</a><a href="/products">Products</a>@endforelse
        </nav>
    </aside>
    @endif

    <main id="main" class="{{ $container ? 'mod-container' : '' }}">
        @yield('content')
    </main>

    @if($chrome)
    <footer class="mod-footer">
        <div class="mod-container">
            <div class="mod-footer__row">
                <div>
                    <a class="mod-brand mod-brand--sm" href="/">{{ $store->name }}</a>
                    @if($store->description ?? null)<p class="mod-footer__about">{{ $store->description }}</p>@endif
                </div>
                <nav class="mod-footer__links">
                    @forelse($footer as $it)<a href="{{ $it['url'] }}">{{ $it['label'] }}</a>@empty<a href="/products">Products</a>@endforelse
                </nav>
            </div>
            <div class="mod-footer__meta">© {{ $store->name }}@if($store->email ?? null) · {{ $store->email }}@endif @if($store->phone ?? null)· {{ $store->phone }}@endif</div>
        </div>
    </footer>
    @endif
    @include('storefront.studio-bridge')
</body>
</html>

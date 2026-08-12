@php
    $store = (object) $context['store'];
    $seo = $context['seo'];
    $data = $context['data'];
    $theme = $context['theme'];
@endphp<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] ?? $store->name }}</title>
    <meta name="description" content="{{ $seo['description'] ?? '' }}">
    <meta name="robots" content="{{ $seo['robots'] ?? 'index, follow' }}">
    <link rel="canonical" href="{{ $seo['canonical'] ?? '' }}">
    @foreach(($seo['og'] ?? []) as $property => $content)
        <meta property="{{ $property }}" content="{{ $content }}">
    @endforeach
    @foreach(($seo['twitter'] ?? []) as $name => $content)
        <meta name="{{ $name }}" content="{{ $content }}">
    @endforeach
    @if(!empty($seo['json_ld']))
        <script type="application/ld+json">{!! json_encode($seo['json_ld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif
    <style data-critical-css="fallback">
        body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;color:#0f172a;background:#f8fafc}
        .wrap{max-width:960px;margin:0 auto;padding:24px}
        header{border-bottom:1px solid #e2e8f0;background:#fff}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px}
        .card{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:14px}
        .muted{color:#64748b;font-size:14px}.price{font-weight:600}
        a{color:#2563eb;text-decoration:none}nav a{margin-inline-end:16px}
        [data-studio-selected="true"]{outline:2px solid #0d8795;outline-offset:2px}
    </style>
    @if(!empty($theme['custom_css']))<style data-store-custom-css>{!! $theme['custom_css'] !!}</style>@endif
    @if(!empty($theme['responsive_css']))<style data-responsive-section-css>{!! $theme['responsive_css'] !!}</style>@endif
</head>
<body id="storefront-root" data-rendered-by="blade" data-theme="{{ $theme['key'] }}" data-theme-version="{{ $theme['version'] }}">
    <header>
        <div class="wrap">
            <h1 style="margin:0">{{ $store->name }}</h1>
            <nav style="margin-top:8px">
                @php($headerMenu = $context['navigation']['header'] ?? [])
                @if(count($headerMenu))
                    @foreach($headerMenu as $item)
                        <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                    @endforeach
                @else
                    <a href="/">Home</a><a href="/products">Products</a>
                @endif
            </nav>
        </div>
    </header>
    <main class="wrap">
        @foreach($context['page']['sections'] as $section)
            <div data-studio-section-id="{{ $section['id'] ?? '' }}">@includeIf('storefront.sections.'.$section['type'], ['section' => $section, 'ctx' => $context, 'store' => $store, 'data' => $data])</div>
        @endforeach
    </main>
    @php($footerMenu = $context['navigation']['footer'] ?? [])
    @if(count($footerMenu))
        <nav class="wrap muted" style="border-top:1px solid #e2e8f0">
            @foreach($footerMenu as $item)<a href="{{ $item['url'] }}">{{ $item['label'] }}</a>@endforeach
        </nav>
    @endif
    <footer class="wrap muted">
        <hr style="border:none;border-top:1px solid #e2e8f0">
        <p>{{ $store->name }} @if($store->email)· {{ $store->email }}@endif @if($store->phone)· {{ $store->phone }}@endif</p>
    </footer>
    @include('storefront.studio-bridge')
</body>
</html>

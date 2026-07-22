<!DOCTYPE html>
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
    <style>
        body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;color:#0f172a;background:#f8fafc}
        .wrap{max-width:960px;margin:0 auto;padding:24px}
        header{border-bottom:1px solid #e2e8f0;background:#fff}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px}
        .card{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:14px}
        .muted{color:#64748b;font-size:14px}
        a{color:#2563eb;text-decoration:none}
        nav a{margin-inline-end:16px}
        .price{font-weight:600}
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <h1 style="margin:0">{{ $store->name }}</h1>
            @if($store->description)<p class="muted" style="margin:.25rem 0 0">{{ $store->description }}</p>@endif
            <nav style="margin-top:12px">
                <a href="/">Home</a>
                <a href="/products">Products</a>
            </nav>
        </div>
    </header>
    <main class="wrap">
        @yield('content')
    </main>
    <footer class="wrap muted">
        <hr style="border:none;border-top:1px solid #e2e8f0">
        <p>{{ $store->name }} @if($store->email)· {{ $store->email }}@endif @if($store->phone)· {{ $store->phone }}@endif</p>
    </footer>
</body>
</html>

@php
    $title = $seoMeta['title'] ?? config('app.name');
    $description = $seoMeta['description'] ?? '';
    $image = $seoMeta['image'] ?? asset('logo.png');
    $url = $seoMeta['url'] ?? url()->current();
    $type = $seoMeta['type'] ?? 'website';
@endphp
<meta name="description" content="{{ $description }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:image" content="{{ $image }}">
<meta property="og:url" content="{{ $url }}">
<meta property="og:type" content="{{ $type }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<link rel="canonical" href="{{ $url }}">

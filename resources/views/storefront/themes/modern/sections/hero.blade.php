@php $s = $section['settings'] ?? []; $hero = $ctx['data']['hero'] ?? []; @endphp
<section data-section="hero" class="mod-hero">
    @if(!empty($s['eyebrow']) || !empty($hero['eyebrow']))<div class="mod-hero__eyebrow">{{ $s['eyebrow'] ?? $hero['eyebrow'] }}</div>@endif
    <h1>{{ $s['headline'] ?? ($hero['title'] ?? ($ctx['store']['name'] ?? '')) }}</h1>
    @if(($s['show_tagline'] ?? true) && (!empty($s['subhead']) || !empty($hero['tagline'])))<p>{{ $s['subhead'] ?? $hero['tagline'] }}</p>@endif
    <a class="mod-btn" href="{{ $s['cta_url'] ?? '/products' }}">{{ $s['cta_label'] ?? 'تسوّق الآن' }}</a>
</section>

@php($hero = $data['hero'] ?? null)
<section class="card" style="margin-bottom:24px" data-section="hero">
    <h2 style="margin-top:0">{{ $section['settings']['headline'] ?? ($hero['title'] ?? $store->name) }}</h2>
    @if(($section['settings']['show_tagline'] ?? true) && !empty($hero['tagline']))
        <p class="muted">{{ $hero['tagline'] }}</p>
    @endif
</section>

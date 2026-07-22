@php $s = $section['settings'] ?? []; @endphp
<section data-section="promo-banner" class="mod-promo" @if(!empty($s['image']))style="background-image:linear-gradient(90deg,rgba(2,6,23,.6),rgba(2,6,23,.15)),url('{{ $s['image'] }}')"@endif>
    @if(!empty($s['eyebrow']))<span class="mod-promo__eyebrow">{{ $s['eyebrow'] }}</span>@endif
    <h2>{{ $s['title'] ?? 'عرض خاص لفترة محدودة' }}</h2>
    @if(!empty($s['text']))<p>{{ $s['text'] }}</p>@endif
    <a class="mod-btn" href="{{ $s['cta_url'] ?? '/products' }}">{{ $s['cta_label'] ?? 'تسوّق الآن' }}</a>
</section>

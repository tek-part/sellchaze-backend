@php $pct = (function ($price, $compare) { $p = (float) $price; $c = (float) $compare; return ($c > 0 && $p > 0 && $c > $p) ? (int) round((1 - $p / $c) * 100) : 0; })($p['price'], $p['compare_price'] ?? 0); @endphp
<a class="mod-card" href="/products/{{ $p['slug'] }}">
    <div class="mod-card__media">@if($p['image_url'] ?? null)<img src="{{ $p['image_url'] }}" alt="{{ $p['name'] }}" loading="lazy">@else<span class="mod-card__ph">صورة المنتج</span>@endif @if($pct)<span class="mod-badge mod-badge--sale">-{{ $pct }}%</span>@endif</div>
    <div class="mod-card__body">
        <div class="mod-card__name">{{ $p['name'] }}</div>
        <span class="mod-price"><span>{{ $p['price'] }} {{ $cur }}</span>@if(!empty($p['compare_price']) && (float) $p['compare_price'] > (float) $p['price'])<span class="mod-price__was">{{ $p['compare_price'] }} {{ $cur }}</span>@endif</span>
    </div>
</a>

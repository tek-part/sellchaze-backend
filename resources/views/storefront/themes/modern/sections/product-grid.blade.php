@php $s = $section['settings'] ?? []; $products = $ctx['data']['products'] ?? []; $cur = $ctx['store']['currency'] ?? '';
$pct = function ($price, $compare) { $p = (float) $price; $c = (float) $compare; return ($c > 0 && $p > 0 && $c > $p) ? (int) round((1 - $p / $c) * 100) : 0; };
@endphp
<section data-section="product-grid" class="mod-section">
    <div class="mod-section__head"><h2>{{ $s['title'] ?? 'منتجات مختارة' }}</h2><a href="/products">عرض الكل</a></div>
    @if(count($products))
        <div class="mod-grid">
            @foreach($products as $p)
                @php $d = $pct($p['price'] ?? 0, $p['compare_price'] ?? 0); @endphp
                <a class="mod-card" href="/products/{{ $p['slug'] }}">
                    <div class="mod-card__media">
                        @php $pimg = $p['image_url'] ?? ($p['image'] ?? null); @endphp@if($pimg)<img src="{{ $pimg }}" alt="{{ $p['name'] }}" loading="lazy">@else<span class="mod-card__ph">صورة المنتج</span>@endif
                        @if($d)<span class="mod-badge mod-badge--sale">-{{ $d }}%</span>@endif
                    </div>
                    <div class="mod-card__body">
                        <div class="mod-card__name">{{ $p['name'] }}</div>
                        <span class="mod-price"><span>{{ $p['price'] }} {{ $cur }}</span>@if(!empty($p['compare_price']) && (float) $p['compare_price'] > (float) $p['price'])<span class="mod-price__was">{{ $p['compare_price'] }} {{ $cur }}</span>@endif</span>
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="mod-empty">لا توجد منتجات بعد.</div>
    @endif
</section>

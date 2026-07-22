@php
    $p = $ctx['data']['product'] ?? null;
    $related = $ctx['data']['related'] ?? [];
    $cur = $ctx['store']['currency'] ?? '';
@endphp
@if($p)
@php
    $img = $p['image_url'] ?? ($p['image'] ?? '');
    $gallery = (!empty($p['gallery']) && is_array($p['gallery'])) ? $p['gallery'] : ($img ? [$img, $img, $img, $img] : []);
    $id = (string) ($p['id'] ?? 0);
    $sku = 'SC-' . str_pad($id, 5, '0', STR_PAD_LEFT);
    $specs = [
        ['العلامة التجارية', $p['category']['name'] ?? 'SellChase'],
        ['الحالة', 'جديد'],
        ['رقم المنتج (SKU)', $sku],
        ['الباركود', '628' . str_pad($id, 10, '0', STR_PAD_LEFT)],
        ['بلد المنشأ', 'مستورد'],
        ['الضمان', 'سنة واحدة'],
    ];
    $reviews = [
        ['name' => 'سارة الف.', 'rating' => 5, 'text' => 'المنتج ممتاز والجودة رائعة، وصل بسرعة وبتغليف احترافي.'],
        ['name' => 'محمد ع.', 'rating' => 4, 'text' => 'قيمة ممتازة مقابل السعر، أنصح بالشراء دون تردد.'],
        ['name' => 'نورة ح.', 'rating' => 5, 'text' => 'مطابق للوصف تماماً وسأكرر الطلب بالتأكيد.'],
    ];
    $dist = [78, 14, 5, 2, 1];
    $rating = 4.6; $count = 128;
    $swatches = ['#0E7C66', '#E8552B', '#1F2937', '#C9A227'];
    $sizes = [['S', false], ['M', false], ['L', false], ['XL', true]];
    $desc = $p['short_description'] ?? ($p['description'] ?? null);
    $stars = fn ($n) => str_repeat('★', (int) round($n)) . str_repeat('☆', 5 - (int) round($n));
@endphp
<article data-section="product-details" class="mod-pd">
    <div class="mod-pd__gallery">
        @if($img)<img src="{{ $img }}" alt="{{ $p['name'] }}">@else<span class="mod-card__ph">معرض الصور</span>@endif
        @if($gallery)<div class="mod-pd__thumbs">@foreach($gallery as $i => $g)<button type="button" class="mod-pd__thumb{{ $i === 0 ? ' is-active' : '' }}"><img src="{{ $g }}" alt=""></button>@endforeach</div>@endif
    </div>
    <div class="mod-pd__info">
        @if(!empty($p['category']['name']))<span class="mod-badge">{{ $p['category']['name'] }}</span>@endif
        <h1>{{ $p['name'] }}</h1>
        <span class="mod-rating" aria-label="{{ $rating }} / 5"><span class="mod-rating__stars">{{ $stars($rating) }}</span><span class="mod-rating__count">({{ $count }})</span></span>
        <span class="mod-price mod-price--lg"><span>{{ $p['price'] }} {{ $cur }}</span>@if(!empty($p['compare_price']) && (float) $p['compare_price'] > (float) $p['price'])<span class="mod-price__was">{{ $p['compare_price'] }} {{ $cur }}</span>@endif</span>
        @if($desc)<p class="mod-pd__desc">{{ $desc }}</p>@endif
        <div class="mod-pd__label">اللون</div>
        <div class="mod-swatches">@foreach($swatches as $i => $c)<button type="button" aria-label="اللون" class="mod-swatch{{ $i === 0 ? ' is-active' : '' }}" style="background:{{ $c }}"></button>@endforeach</div>
        <div class="mod-pd__label">المقاس</div>
        <div class="mod-sizes">@foreach($sizes as $i => $z)<button type="button" class="mod-size{{ $i === 1 ? ' is-active' : '' }}{{ $z[1] ? ' is-off' : '' }}">{{ $z[0] }}</button>@endforeach</div>
        <div class="mod-stock">● متوفر في المخزون</div>
        <div class="mod-buyrow">
            <div class="mod-qty"><button type="button" aria-label="إنقاص">−</button><span>١</span><button type="button" aria-label="زيادة">+</button></div>
            <button class="mod-btn" type="button">أضف إلى السلة</button>
            <button class="mod-btn mod-btn--ghost" type="button">اشترِ الآن</button>
        </div>
        <div class="mod-buyrow">
            <button type="button" class="mod-btn mod-btn--ghost">♡ أضف للمفضلة</button>
            <button type="button" class="mod-btn mod-btn--ghost">⇄ قارن</button>
            <button type="button" class="mod-btn mod-btn--ghost">↗ مشاركة</button>
        </div>
        <div class="mod-trust"><span>🚚 توصيل خلال ٢–٤ أيام</span><span>↩ إرجاع خلال ١٤ يوماً</span><span>🔒 دفع آمن ١٠٠٪</span></div>
        <div class="mod-pd__sku">رقم المنتج: {{ $sku }}</div>
    </div>
</article>
<section class="mod-section">
    <div class="mod-tabs"><button type="button" class="mod-tab is-active">الوصف</button><button type="button" class="mod-tab">المواصفات</button><button type="button" class="mod-tab">الشحن والإرجاع</button><button type="button" class="mod-tab">التقييمات</button></div>
    <div class="mod-pd__panel">
        <h3>الوصف</h3>
        <p class="mod-pd__desc">{{ $p['description'] ?? ($desc ?? 'منتج عالي الجودة مصمم ليمنحك تجربة استخدام مثالية بأناقة تدوم.') }}</p>
    </div>
    <div class="mod-pd__panel">
        <h3>المواصفات</h3>
        <table class="mod-spec"><tbody>@foreach($specs as $r)<tr><th>{{ $r[0] }}</th><td>{{ $r[1] }}</td></tr>@endforeach</tbody></table>
    </div>
    <div class="mod-pd__panel">
        <h3>الشحن والإرجاع</h3>
        <p class="mod-pd__desc">نشحن لجميع المدن خلال ٢–٤ أيام عمل. الإرجاع مجاني خلال ١٤ يوماً من الاستلام مع استرداد كامل للمبلغ.</p>
    </div>
    <div class="mod-pd__panel">
        <h3>التقييمات</h3>
        <div class="mod-rev">
            <div class="mod-rev__summary">
                <div class="mod-rev__score">{{ $rating }}</div>
                <span class="mod-rating" aria-label="{{ $rating }} / 5"><span class="mod-rating__stars">{{ $stars($rating) }}</span></span>
                <div class="mod-pd__sku">{{ $count }} تقييم</div>
            </div>
            <div class="mod-rev__bars">@foreach($dist as $i => $pct)<div class="mod-rev__barrow"><span>{{ 5 - $i }}★</span><div class="mod-rev__bar"><span style="width:{{ $pct }}%"></span></div><span>{{ $pct }}%</span></div>@endforeach</div>
        </div>
        <div>@foreach($reviews as $rv)<div class="mod-review"><div class="mod-review__head"><span class="mod-review__name">{{ $rv['name'] }}</span><span class="mod-rating" aria-label="{{ $rv['rating'] }} / 5"><span class="mod-rating__stars">{{ $stars($rv['rating']) }}</span></span></div><p class="mod-pd__desc">{{ $rv['text'] }}</p></div>@endforeach</div>
    </div>
</section>
@if(!empty($related))
<section class="mod-section">
    <div class="mod-section__head"><h2>منتجات ذات صلة</h2><a href="/products">عرض الكل</a></div>
    <div class="mod-grid">@foreach($related as $rp)@include('storefront.themes.modern.pages._card', ['p' => $rp, 'cur' => $cur])@endforeach</div>
</section>
@endif
<div class="mod-stickybar">
    <div class="mod-stickybar__row">
        <div class="mod-review__name">{{ $p['name'] }}</div>
        <div class="mod-buyrow">
            <span class="mod-price"><span>{{ $p['price'] }} {{ $cur }}</span>@if(!empty($p['compare_price']) && (float) $p['compare_price'] > (float) $p['price'])<span class="mod-price__was">{{ $p['compare_price'] }} {{ $cur }}</span>@endif</span>
            <button class="mod-btn" type="button">أضف إلى السلة</button>
        </div>
    </div>
</div>
@endif

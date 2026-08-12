@php
    $d = $ctx['data'] ?? [];
    $products = $d['products'] ?? [];
    $cats = $d['categories'] ?? [];
    $cat = $d['category'] ?? null;
    $cur = $ctx['store']['currency'] ?? '';
    $total = $d['total'] ?? count($products);
    $pmin = $d['price_min'] ?? 0;
    $pmax = $d['price_max'] ?? 0;
    $brands = ['Sellchaze', 'Aura', 'Lumen', 'Nova', 'Verde'];
    $sorts = ['الأحدث', 'الأكثر مبيعاً', 'السعر: من الأقل', 'السعر: من الأعلى', 'الأعلى تقييماً'];
    $chips = array_values(array_filter([$cat['name'] ?? null, '★★★★☆ فأكثر', 'حتى ' . $pmax . ' ' . $cur]));
@endphp
<section data-section="catalog" class="mod-section mod-catalog">
    <nav class="mod-crumb" aria-label="breadcrumb"><a href="/">الرئيسية</a><a href="/products">المتجر</a>@if($cat)<span aria-current="page">{{ $cat['name'] }}</span>@endif</nav>
    <div class="mod-catalog__bar">
        <span class="mod-catalog__count">عرض {{ count($products) }} من {{ $total }} منتج</span>
        <label class="mod-catalog__sort"><span>ترتيب:</span><select class="mod-select">@foreach($sorts as $o)<option>{{ $o }}</option>@endforeach</select></label>
    </div>
    <div class="mod-catalog__body">
        <aside class="mod-filters">
            <div class="mod-filters__head"><strong>تصفية</strong><button type="button" class="mod-filters__clear">مسح الكل</button></div>
            <div class="mod-filter">
                <div class="mod-filter__title">الفئات</div>
                <div>@foreach($cats as $c)<a href="/categories/{{ $c['slug'] }}" class="mod-filter__opt{{ $cat && $c['slug'] === $cat['slug'] ? ' is-active' : '' }}"><span>{{ $c['name'] }}</span><span class="mod-filter__count">{{ $c['products_count'] }}</span></a>@endforeach</div>
            </div>
            <div class="mod-filter">
                <div class="mod-filter__title">السعر</div>
                <div>
                    <div class="mod-range"><span class="mod-range__fill"></span></div>
                    <div class="mod-range__vals"><span>{{ $pmin }} {{ $cur }}</span><span>{{ $pmax }} {{ $cur }}</span></div>
                </div>
            </div>
            <div class="mod-filter">
                <div class="mod-filter__title">العلامة التجارية</div>
                <div>@foreach($brands as $i => $b)<label class="mod-check"><input type="checkbox" @if($i === 0)checked @endif><span>{{ $b }}</span></label>@endforeach</div>
            </div>
            <div class="mod-filter">
                <div class="mod-filter__title">التقييم</div>
                <div>@foreach([5, 4, 3] as $i => $r)<label class="mod-check"><input type="radio" name="rate" @if($i === 0)checked @endif><span>{{ str_repeat('★', $r) . str_repeat('☆', 5 - $r) }} فأكثر</span></label>@endforeach</div>
            </div>
            <div class="mod-filter">
                <div class="mod-filter__title">التوفر</div>
                <div><label class="mod-check"><input type="checkbox"><span>المتوفر فقط</span></label></div>
            </div>
        </aside>
        <div class="mod-catalog__main">
            <div class="mod-chips">@foreach($chips as $c)<span class="mod-chip">{{ $c }}<span class="mod-chip__x">×</span></span>@endforeach</div>
            @if($products)
                <div class="mod-grid">@foreach($products as $p)@include('storefront.themes.modern.pages._card', ['p' => $p, 'cur' => $cur])@endforeach</div>
                <nav class="mod-pager" aria-label="pagination">
                    <span class="mod-pager__link is-disabled">»</span>
                    <a href="#" class="mod-pager__link is-active">١</a>
                    <a href="#" class="mod-pager__link">٢</a>
                    <a href="#" class="mod-pager__link">٣</a>
                    <span class="mod-pager__gap">…</span>
                    <a href="#" class="mod-pager__link">«</a>
                </nav>
            @else
                <div class="mod-empty">لا توجد منتجات مطابقة.</div>
            @endif
        </div>
    </div>
</section>

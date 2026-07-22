@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-account">
    @include('storefront.themes.modern.pages.account._nav', ['active' => $active])
    <div>
        <div class="mod-page__head"><h1>تقييماتي</h1><p>آراؤك تساعد الآخرين على الشراء بثقة.</p></div>
        <div class="mod-panel" style="margin-bottom:var(--sp-5);display:flex;gap:var(--sp-4);align-items:center">
            <div style="font-size:28px">⭐</div>
            <div><strong>منتجات بانتظار التقييم</strong><div style="color:var(--muted)">لديك 3 منتجات اشتريتها ولم تقيّمها بعد.</div></div>
            <a class="mod-btn" href="#" style="margin-inline-start:auto">قيّم الآن</a>
        </div>
        @foreach($items as $it)
        <div class="mod-line" style="align-items:flex-start">
            <div class="mod-line__media">@if($it['image_url'])<img src="{{ $it['image_url'] }}" alt="{{ $it['name'] }}">@endif</div>
            <div class="mod-line__info">
                <div class="mod-line__name">{{ $it['name'] }}</div>
                <div style="color:var(--accent)">★★★★★</div>
                <p style="color:var(--muted);margin:6px 0">منتج رائع وجودة ممتازة، أنصح به بشدة. كان التوصيل سريعاً والتغليف احترافياً.</p>
                <div style="color:var(--muted);font-size:13px">٢٠ أكتوبر ٢٠٢٥</div>
            </div>
            <div style="display:flex;gap:6px"><button class="mod-btn mod-btn--ghost" type="button">تعديل</button><button class="mod-iconbtn" type="button" aria-label="حذف">🗑</button></div>
        </div>
        @endforeach
    </div>
</div>
@endsection

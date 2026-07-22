@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-page">
    @if($q === '')
        <div class="mod-centered"><div class="mod-centered__inner">
            <div style="font-size:48px">🔍</div>
            <h1 style="font-size:var(--fs-xl)">ابحث عن منتجك المفضّل</h1>
            <p style="color:var(--muted)">اكتب اسم المنتج أو الفئة للبدء.</p>
            <form class="mod-couponbar" action="/search" method="get" style="width:min(520px,90vw)"><input class="mod-input" name="q" placeholder="ابحث..."><button class="mod-btn" type="submit">بحث</button></form>
            <div style="color:var(--muted)">الأكثر بحثاً: <a href="/search?q=ساعة" style="color:var(--primary)">ساعة</a> · <a href="/search?q=سماعة" style="color:var(--primary)">سماعة</a> · <a href="/search?q=حقيبة" style="color:var(--primary)">حقيبة</a></div>
        </div></div>
    @else
        <div class="mod-page__head"><h1>نتائج البحث عن "{{ $q }}"</h1><p>{{ count($items) }} منتج مطابق.</p></div>
        <div class="mod-grid">@foreach($items as $p) @include('storefront.themes.modern.pages._card', ['p' => $p, 'cur' => $currency]) @endforeach</div>
    @endif
</div>
@endsection

@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-centered"><div class="mod-centered__inner">
    <div style="font-size:64px;font-weight:800;color:var(--primary)">٤٠٤</div>
    <h1 style="font-size:var(--fs-2xl)">الصفحة غير موجودة</h1>
    <p style="color:var(--muted)">عذراً، لم نتمكن من العثور على الصفحة التي تبحث عنها.</p>
    <form class="mod-couponbar" action="/search" method="get" style="width:min(480px,90vw)"><input class="mod-input" name="q" placeholder="ابحث عن منتج..."><button class="mod-btn" type="submit">بحث</button></form>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center">
        <a class="mod-chip" href="/categories/cat-1">إلكترونيات</a><a class="mod-chip" href="/categories/cat-2">أزياء</a><a class="mod-chip" href="/products">كل المنتجات</a>
    </div>
    <a class="mod-btn" href="/">← العودة للرئيسية</a>
</div></div>
@endsection

@extends('storefront.themes.modern.layout')
@section('content')
@php $cur = $currency; @endphp
<div class="mod-page">
    <div class="mod-page__head" style="text-align:center"><h1>قائمة الرغبات</h1><p>لديك {{ count($items) }} منتجات محفوظة. احتفظ بها هنا حتى تصبح جاهزاً للشراء.</p></div>
    <div style="display:flex;gap:var(--sp-3);justify-content:center;margin-bottom:var(--sp-6)">
        <button class="mod-btn mod-btn--ghost" type="button">↗ مشاركة القائمة</button>
        <a class="mod-btn" href="/cart">🛍 نقل الكل إلى السلة</a>
    </div>
    <div class="mod-grid">
        @foreach($items as $p) @include('storefront.themes.modern.pages._card', ['p' => $p, 'cur' => $cur]) @endforeach
    </div>
    <section class="mod-section">
        <div class="mod-section__head"><h2>قد يعجبك أيضاً</h2><a href="/products">عرض الكل</a></div>
        <div class="mod-grid">
            @foreach(array_slice($items, 0, 4) as $p) @include('storefront.themes.modern.pages._card', ['p' => $p, 'cur' => $cur]) @endforeach
        </div>
    </section>
</div>
@endsection

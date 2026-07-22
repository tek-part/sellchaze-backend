@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-account">
    @include('storefront.themes.modern.pages.account._nav', ['active' => $active])
    <div>
        <div class="mod-page__head"><h1>مرحباً بعودتك 👋</h1><p>نظرة سريعة على نشاط حسابك.</p></div>
        <div class="mod-statgrid">
            <div class="mod-stat"><div class="mod-stat__n">12</div><div class="mod-stat__l">الطلبات</div></div>
            <div class="mod-stat"><div class="mod-stat__n">{{ count($wishlist) }}</div><div class="mod-stat__l">قائمة الرغبات</div></div>
            <div class="mod-stat"><div class="mod-stat__n">3</div><div class="mod-stat__l">العناوين</div></div>
            <div class="mod-stat"><div class="mod-stat__n">240</div><div class="mod-stat__l">نقاط المكافآت</div></div>
        </div>
        <div class="mod-section__head"><h2 style="font-size:var(--fs-xl)">أحدث الطلبات</h2><a href="/account/orders">عرض الكل</a></div>
        @foreach($orders as $o) @include('storefront.themes.modern.pages.account._order', ['o' => $o, 'cur' => $currency]) @endforeach
        <div class="mod-section__head" style="margin-top:var(--sp-6)"><h2 style="font-size:var(--fs-xl)">من قائمة رغباتك</h2><a href="/wishlist">عرض الكل</a></div>
        <div class="mod-grid">@foreach($wishlist as $p) @include('storefront.themes.modern.pages._card', ['p' => $p, 'cur' => $currency]) @endforeach</div>
    </div>
</div>
@endsection

@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-account">
    @include('storefront.themes.modern.pages.account._nav', ['active' => $active])
    <div>
        <div class="mod-page__head"><h1>طلباتي</h1><p>تابع حالة طلباتك وأعد الطلب بسهولة.</p></div>
        <div style="display:flex;gap:var(--sp-2);margin-bottom:var(--sp-5);flex-wrap:wrap">
            <button class="mod-btn mod-btn--ghost" type="button" style="border-color:var(--primary);color:var(--primary)">الكل</button>
            <button class="mod-btn mod-btn--ghost" type="button">قيد التجهيز</button>
            <button class="mod-btn mod-btn--ghost" type="button">تم الشحن</button>
            <button class="mod-btn mod-btn--ghost" type="button">تم التسليم</button>
        </div>
        @foreach($orders as $o) @include('storefront.themes.modern.pages.account._order', ['o' => $o, 'cur' => $currency]) @endforeach
    </div>
</div>
@endsection

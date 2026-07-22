@extends('storefront.themes.modern.layout')
@section('content')
@php $cur = $currency; @endphp
<div class="mod-account">
    @include('storefront.themes.modern.pages.account._nav', ['active' => $active])
    <div>
        <div class="mod-page__head"><h1>تفاصيل الطلب #SC-992841</h1><p>تم الطلب في ٢٥ أكتوبر ٢٠٢٥</p></div>
        <div class="mod-cols">
            <div class="mod-panel">
                <h3>حالة الطلب</h3>
                <div class="mod-timeline" style="margin-top:var(--sp-4)">
                    <div class="mod-tl mod-tl--done"><span class="mod-tl__dot">✓</span><div><strong>تم استلام الطلب</strong><div style="color:var(--muted);font-size:14px">٢٥ أكتوبر، ١٠:٣٠ ص</div></div></div>
                    <div class="mod-tl mod-tl--done"><span class="mod-tl__dot">✓</span><div><strong>تم التأكيد</strong><div style="color:var(--muted);font-size:14px">٢٥ أكتوبر، ١١:١٥ ص</div></div></div>
                    <div class="mod-tl mod-tl--done"><span class="mod-tl__dot">✓</span><div><strong>قيد التجهيز</strong><div style="color:var(--muted);font-size:14px">٢٦ أكتوبر</div></div></div>
                    <div class="mod-tl mod-tl--done"><span class="mod-tl__dot">🚚</span><div><strong>تم الشحن</strong><div style="color:var(--muted);font-size:14px">أرامكس · ٢٧ أكتوبر</div></div></div>
                    <div class="mod-tl"><span class="mod-tl__dot">📦</span><div><strong>التسليم</strong><div style="color:var(--muted);font-size:14px">متوقع ٢٨ أكتوبر</div></div></div>
                </div>
                <h3 style="margin-top:var(--sp-6)">المنتجات</h3>
                @foreach($items as $it)
                <div class="mod-line">
                    <div class="mod-line__media">@if($it['image_url'])<img src="{{ $it['image_url'] }}" alt="{{ $it['name'] }}">@endif</div>
                    <div class="mod-line__info"><div class="mod-line__name">{{ $it['name'] }}</div><div style="color:var(--muted);font-size:14px">الكمية: 1</div></div>
                    <div class="mod-price">{{ $it['price'] }} {{ $cur }}</div>
                </div>
                @endforeach
            </div>
            <aside class="mod-summary">
                <h3>ملخص الدفع</h3>
                <div><strong>عنوان الشحن</strong><div style="color:var(--muted);font-size:14px">حي النخيل، طريق الملك عبد العزيز، الرياض</div></div>
                <div><strong>طريقة الدفع</strong><div style="color:var(--muted);font-size:14px">بطاقة مدى ⁕⁕ 4492</div></div>
                <hr style="border:none;border-top:1px solid var(--border)">
                <div class="mod-sumrow"><span>المجموع الفرعي</span><span>1,749 {{ $cur }}</span></div>
                <div class="mod-sumrow"><span>ضريبة (١٥٪)</span><span>262 {{ $cur }}</span></div>
                <div class="mod-sumrow mod-sumrow--total"><span>الإجمالي</span><span>2,011 {{ $cur }}</span></div>
                <a class="mod-btn" href="#" style="justify-content:center">إعادة الطلب</a>
                <button class="mod-btn mod-btn--ghost" type="button">تحميل الفاتورة</button>
            </aside>
        </div>
    </div>
</div>
@endsection

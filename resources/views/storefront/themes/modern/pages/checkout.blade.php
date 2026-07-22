@extends('storefront.themes.modern.layout')
@section('content')
@php
    $cur = $currency;
    $subtotal = 0; foreach ($items as $it) { $subtotal += (float) $it['price']; }
    $shipping = 25; $discount = 50; $tax = round($subtotal * 0.15, 2); $total = $subtotal + $shipping + $tax - $discount;
@endphp
<div class="mod-page">
    <div class="mod-page__head"><h1>إتمام عملية الشراء</h1></div>
    <div class="mod-steps">
        <div class="mod-step mod-step--active"><span class="mod-step__dot">📍</span> الشحن</div>
        <span class="mod-steps__line"></span>
        <div class="mod-step"><span class="mod-step__dot">💳</span> الدفع</div>
        <span class="mod-steps__line"></span>
        <div class="mod-step"><span class="mod-step__dot">✓</span> المراجعة</div>
    </div>
    <div class="mod-cols">
        <div class="mod-panel">
            <h3 style="margin-bottom:var(--sp-4)">عنوان الشحن</h3>
            <div class="mod-optcard mod-optcard--active"><div><strong>المنزل 🏠</strong><div style="color:var(--muted);font-size:14px">حي النخيل، شارع الملك عبد العزيز، الرياض</div></div><span style="color:var(--primary)">✓</span></div>
            <div class="mod-optcard"><div><strong>العمل</strong><div style="color:var(--muted);font-size:14px">طريق الملك فهد، الرياض</div></div></div>
            <a href="#" style="color:var(--primary);font-weight:600">+ إضافة عنوان جديد</a>
            <h3 style="margin:var(--sp-6) 0 var(--sp-4)">خيارات التوصيل</h3>
            <div class="mod-optcard mod-optcard--active"><div><strong>توصيل عادي (مجاني)</strong><div style="color:var(--muted);font-size:14px">خلال ٣–٥ أيام عمل</div></div><span>0 {{ $cur }}</span></div>
            <div class="mod-optcard"><div><strong>توصيل سريع</strong><div style="color:var(--muted);font-size:14px">خلال ٢٤–٤٨ ساعة</div></div><span>25 {{ $cur }}</span></div>
            <button class="mod-btn" type="button" style="width:100%;justify-content:center;margin-top:var(--sp-4)">الاستمرار إلى الدفع ←</button>
            <div class="mod-trust"><span>🔒 SSL آمن</span><span>Verified by Norton</span><span>ضمان الاسترجاع 100%</span></div>
        </div>
        <aside class="mod-summary">
            <h3>ملخص الطلب</h3>
            <div class="mod-sumitems">
                @foreach($items as $it)
                <div class="mod-sumitem">
                    <div class="mod-sumitem__media"><img src="{{ $it['image_url'] }}" alt="{{ $it['name'] }}"><span class="mod-sumitem__qty">١</span></div>
                    <div class="mod-sumitem__info"><div class="mod-sumitem__name">{{ $it['name'] }}</div><div style="color:var(--muted);font-size:13px">الكمية: ١</div></div>
                    <span>{{ $it['price'] }} {{ $cur }}</span>
                </div>
                @endforeach
            </div>
            <div class="mod-sumrow"><span>المجموع الفرعي</span><span>{{ number_format($subtotal, 2) }} {{ $cur }}</span></div>
            <div class="mod-sumrow"><span>تكلفة التوصيل</span><span>{{ $shipping }} {{ $cur }}</span></div>
            <div class="mod-sumrow"><span>ضريبة القيمة المضافة (١٥٪)</span><span>{{ $tax }} {{ $cur }}</span></div>
            <div class="mod-sumrow"><span>الخصم (WELCOME50)</span><span style="color:var(--sale)">− {{ $discount }} {{ $cur }}</span></div>
            <div class="mod-couponbar"><input class="mod-input" placeholder="كود الخصم"><button class="mod-btn mod-btn--ghost" type="button">تطبيق</button></div>
            <div class="mod-sumrow mod-sumrow--total"><span>الإجمالي الكلي</span><span>{{ number_format($total, 2) }} {{ $cur }}</span></div>
        </aside>
    </div>
</div>
@endsection

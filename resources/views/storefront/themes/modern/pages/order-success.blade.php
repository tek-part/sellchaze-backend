@extends('storefront.themes.modern.layout')
@section('content')
@php
    $cur = $currency;
    $subtotal = 0; foreach ($items as $it) { $subtotal += (float) $it['price']; }
    $tax = round($subtotal * 0.15, 2); $total = $subtotal + $tax;
@endphp
<div class="mod-success">
    <div class="mod-success__icon">✓</div>
    <h1>شكراً لطلبك!</h1>
    <p style="color:var(--muted)">لقد استلمنا طلبك بنجاح، وسنقوم بمراجعة تحضير السلة عبر بريدك الإلكتروني قريباً.</p>
</div>
<div class="mod-panel" style="max-width:720px;margin:0 auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--sp-4)"><strong>رقم الطلب: #SC-992841</strong><span class="mod-badge mod-badge--success">قيد التجهيز</span></div>
    <div class="mod-sumrow"><span>الاثنين، ٢٥ أكتوبر ٢٠٢٥</span><span>بطاقة مدى ⁕⁕ 4492</span></div>
    <div class="mod-sumrow"><span>أرامكس — شحن سريع</span><span>الرياض، طريق الملك عبد العزيز</span></div>
    <hr style="border:none;border-top:1px solid var(--border);margin:var(--sp-4) 0">
    <div class="mod-sumrow"><span>المجموع الفرعي</span><span>{{ number_format($subtotal, 2) }} {{ $cur }}</span></div>
    <div class="mod-sumrow"><span>ضريبة القيمة المضافة (١٥٪)</span><span>{{ $tax }} {{ $cur }}</span></div>
    <div class="mod-sumrow mod-sumrow--total"><span>الإجمالي الكلي</span><span>{{ number_format($total, 2) }} {{ $cur }}</span></div>
    <div style="display:flex;gap:var(--sp-3);margin-top:var(--sp-4)"><a class="mod-btn mod-btn--ghost" href="/account/orders" style="flex:1;justify-content:center">📦 تتبع الطلب</a><a class="mod-btn" href="/" style="flex:1;justify-content:center">🛍 متابعة التسوق</a></div>
</div>
<section class="mod-section" style="max-width:1040px;margin-inline:auto">
    <div class="mod-section__head"><h2>قد يعجبك أيضاً</h2><a href="/products">عرض الكل</a></div>
    <div class="mod-grid">
        @foreach($items as $p) @include('storefront.themes.modern.pages._card', ['p' => $p, 'cur' => $cur]) @endforeach
    </div>
</section>
@endsection

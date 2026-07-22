@extends('storefront.themes.modern.layout')
@section('content')
@php
    $cur = $currency;
    $subtotal = 0; foreach ($items as $it) { $subtotal += (float) $it['price']; }
    $shipping = 30; $discount = 50; $total = $subtotal + $shipping - $discount;
@endphp
<div class="mod-page">
    <div class="mod-page__head"><h1>سلة التسوق</h1><p>لديك {{ count($items) }} منتجات في سلتك.</p></div>
    <div class="mod-cols">
        <div class="mod-panel">
            @foreach($items as $it)
            <div class="mod-line">
                <div class="mod-line__media">@if($it['image_url'])<img src="{{ $it['image_url'] }}" alt="{{ $it['name'] }}">@endif</div>
                <div class="mod-line__info"><div class="mod-line__name">{{ $it['name'] }}</div><div style="color:var(--muted);font-size:14px">اللون: أسود · المقاس: M</div></div>
                <div class="mod-qty"><button type="button">−</button><span>1</span><button type="button">+</button></div>
                <div class="mod-price" style="min-width:90px;text-align:end">{{ $it['price'] }} {{ $cur }}</div>
                <button class="mod-iconbtn" type="button" aria-label="إزالة">🗑</button>
            </div>
            @endforeach
            <a href="/products" style="display:inline-block;margin-top:var(--sp-4);color:var(--primary);font-weight:600">← متابعة التسوق</a>
        </div>
        <aside class="mod-summary">
            <h3>ملخص الطلب</h3>
            <div class="mod-sumrow"><span>المجموع الفرعي</span><span>{{ number_format($subtotal, 2) }} {{ $cur }}</span></div>
            <div class="mod-sumrow"><span>الشحن</span><span>{{ $shipping }} {{ $cur }}</span></div>
            <div class="mod-sumrow"><span>الخصم (AHLAN10)</span><span style="color:var(--sale)">− {{ $discount }} {{ $cur }}</span></div>
            <div class="mod-couponbar"><input class="mod-input" placeholder="كود الخصم"><button class="mod-btn mod-btn--ghost" type="button">تطبيق</button></div>
            <div class="mod-sumrow mod-sumrow--total"><span>الإجمالي</span><span>{{ number_format($total, 2) }} {{ $cur }}</span></div>
            <a class="mod-btn" href="/checkout" style="text-align:center;justify-content:center">متابعة الدفع ←</a>
            <div class="mod-trust"><span>🔒 دفع آمن</span><span>↩ إرجاع مجاني</span></div>
        </aside>
    </div>
</div>
@endsection

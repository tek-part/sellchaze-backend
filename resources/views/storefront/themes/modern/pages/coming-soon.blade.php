@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-centered"><div class="mod-centered__inner">
    <span class="mod-badge mod-badge--sale">قريباً</span>
    <div class="mod-brand" style="font-size:var(--fs-2xl)">{{ $context['store']['name'] }}</div>
    <h1 style="font-size:var(--fs-2xl)">شيء رائع في الطريق ✨</h1>
    <p style="color:var(--muted)">نجهّز لك تجربة تسوّق استثنائية. كن أول من يعرف عند الإطلاق.</p>
    <div style="display:flex;gap:var(--sp-3);justify-content:center">
        <div class="mod-panel" style="padding:var(--sp-4) var(--sp-5);text-align:center"><div style="font-size:28px;font-weight:800">١٢</div><div style="color:var(--muted);font-size:13px">يوم</div></div>
        <div class="mod-panel" style="padding:var(--sp-4) var(--sp-5);text-align:center"><div style="font-size:28px;font-weight:800">٠٨</div><div style="color:var(--muted);font-size:13px">ساعة</div></div>
        <div class="mod-panel" style="padding:var(--sp-4) var(--sp-5);text-align:center"><div style="font-size:28px;font-weight:800">٤٥</div><div style="color:var(--muted);font-size:13px">دقيقة</div></div>
    </div>
    <form class="mod-couponbar" style="width:min(480px,90vw)"><input class="mod-input" type="email" placeholder="بريدك الإلكتروني"><button class="mod-btn" type="button">أعلمني</button></form>
</div></div>
@endsection

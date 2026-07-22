@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-centered">
    <div class="mod-centered__inner mod-panel" style="padding:var(--sp-10)">
        <a class="mod-brand" href="/">{{ $context['store']['name'] }}</a>
        <h1 style="font-size:var(--fs-xl)">نسيت كلمة المرور؟</h1>
        <p style="color:var(--muted)">أدخل بريدك الإلكتروني وسنرسل لك رابط استعادة كلمة المرور.</p>
        <div class="mod-field" style="width:100%;text-align:start"><label>البريد الإلكتروني</label><input class="mod-input" type="email" placeholder="example@mail.com"></div>
        <button class="mod-btn" type="button" style="width:100%">إرسال رابط الاستعادة</button>
        <a href="/account/login" style="color:var(--primary);font-weight:600">← العودة لتسجيل الدخول</a>
    </div>
</div>
@endsection

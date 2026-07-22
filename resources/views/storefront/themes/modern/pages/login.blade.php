@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-auth">
    <div class="mod-auth__form">
        <a class="mod-brand" href="/">{{ $context['store']['name'] }}</a>
        <h1 style="font-size:var(--fs-2xl);margin-top:var(--sp-4)">تسجيل الدخول</h1>
        <p style="color:var(--muted)">أهلاً بعودتك! يرجى إدخال تفاصيل حسابك.</p>
        <form style="margin-top:var(--sp-5)">
            <div class="mod-field"><label>البريد الإلكتروني أو رقم الهاتف</label><input class="mod-input" type="email" placeholder="example@mail.com"></div>
            <div class="mod-field"><label>كلمة المرور</label><input class="mod-input" type="password" placeholder="••••••••"></div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--sp-4)">
                <label style="display:flex;gap:8px;align-items:center;color:var(--muted)"><input type="checkbox"> تذكرني</label>
                <a href="/forgot-password" style="color:var(--primary);font-weight:600">نسيت كلمة المرور؟</a>
            </div>
            <button class="mod-btn" type="button" style="width:100%">تسجيل الدخول ←</button>
        </form>
        <div style="display:flex;align-items:center;gap:var(--sp-3);color:var(--muted);margin:var(--sp-5) 0"><span style="flex:1;height:1px;background:var(--border)"></span>أو سجّل عبر<span style="flex:1;height:1px;background:var(--border)"></span></div>
        <div style="display:flex;gap:var(--sp-3)"><button class="mod-btn mod-btn--ghost" type="button" style="flex:1">Google</button><button class="mod-btn mod-btn--ghost" type="button" style="flex:1">Facebook</button></div>
        <p style="text-align:center;color:var(--muted);margin-top:var(--sp-5)">ليس لديك حساب؟ <a href="/account/register" style="color:var(--primary);font-weight:600">إنشاء حساب جديد</a></p>
    </div>
    <div class="mod-auth__aside" style="background-image:linear-gradient(160deg,rgba(14,124,102,.92),rgba(2,20,16,.94)),url('https://picsum.photos/seed/sc-auth-login/900/1200');background-size:cover;background-position:center">
        <div class="mod-brand" style="color:#fff">{{ $context['store']['name'] }}</div>
        <h2>اكتشف عالم الفخامة في مكان واحد.</h2>
        <p style="opacity:.9">انضم إلى أكثر من ٥٠٬٠٠٠ متسوّق يستمتعون بتجربة فريدة وعروض حصرية يومياً.</p>
        <div class="mod-auth__benefit"><span style="font-size:22px">🚚</span><div><strong>توصيل فائق السرعة</strong><div style="opacity:.85;font-size:14px">خلال ٤٨ ساعة داخل جميع المناطق.</div></div></div>
        <div class="mod-auth__benefit"><span style="font-size:22px">🛡️</span><div><strong>ضمان الجودة الأصلي</strong><div style="opacity:.85;font-size:14px">جميع منتجاتنا أصلية ومضمونة.</div></div></div>
    </div>
</div>
@endsection

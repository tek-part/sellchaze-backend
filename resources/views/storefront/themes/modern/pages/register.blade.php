@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-auth">
    <div class="mod-auth__form">
        <a class="mod-brand" href="/">{{ $context['store']['name'] }}</a>
        <h1 style="font-size:var(--fs-2xl);margin-top:var(--sp-4)">إنشاء حساب جديد</h1>
        <p style="color:var(--muted)">لديك حساب بالفعل؟ <a href="/account/login" style="color:var(--primary);font-weight:600">تسجيل الدخول</a></p>
        <form style="margin-top:var(--sp-5)">
            <div class="mod-field"><label>الاسم الكامل</label><input class="mod-input" placeholder="أدخل اسمك الثلاثي"></div>
            <div class="mod-field"><label>البريد الإلكتروني أو رقم الهاتف</label><input class="mod-input" type="email" placeholder="name@example.com"></div>
            <div class="mod-field"><label>كلمة المرور</label><input class="mod-input" type="password" placeholder="••••••••">
                <div style="display:flex;gap:4px;margin-top:4px"><span style="flex:1;height:4px;border-radius:2px;background:var(--success)"></span><span style="flex:1;height:4px;border-radius:2px;background:var(--success)"></span><span style="flex:1;height:4px;border-radius:2px;background:var(--border)"></span></div>
            </div>
            <div class="mod-field"><label>تأكيد كلمة المرور</label><input class="mod-input" type="password" placeholder="••••••••"></div>
            <label style="display:flex;gap:8px;align-items:center;color:var(--muted);margin-bottom:var(--sp-4)"><input type="checkbox"> أوافق على <a href="/legal/terms" style="color:var(--primary)">شروط الاستخدام</a> و<a href="/legal/privacy" style="color:var(--primary)">سياسة الخصوصية</a></label>
            <button class="mod-btn" type="button" style="width:100%">إنشاء حساب جديد</button>
        </form>
    </div>
    <div class="mod-auth__aside" style="background-image:linear-gradient(160deg,rgba(14,124,102,.92),rgba(2,20,16,.94)),url('https://picsum.photos/seed/sc-auth-register/900/1200');background-size:cover;background-position:center">
        <span class="mod-badge" style="background:rgba(255,255,255,.2);color:#fff;align-self:flex-start">عضوية اليوم</span>
        <h2>عالم من المزايا ينتظرك هنا</h2>
        <p style="opacity:.9">أنشئ حسابك الآن واستمتع بمميزات حصرية مصممة لتلبية احتياجاتك.</p>
        <div class="mod-auth__benefit"><span style="font-size:22px">🎁</span><div><strong>خصومات حصرية</strong><div style="opacity:.85;font-size:14px">تصل إلى ٣٠٪ للأعضاء.</div></div></div>
        <div class="mod-auth__benefit"><span style="font-size:22px">⚡</span><div><strong>دفع سريع وآمن</strong><div style="opacity:.85;font-size:14px">محفظة تحفظ بياناتك بأمان.</div></div></div>
        <div class="mod-auth__benefit"><span style="font-size:22px">🛡️</span><div><strong>تتبع كل الطلبات</strong><div style="opacity:.85;font-size:14px">من الهاتف والويب.</div></div></div>
    </div>
</div>
@endsection

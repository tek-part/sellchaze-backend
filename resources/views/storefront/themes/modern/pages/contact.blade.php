@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-page">
    <div class="mod-page__head"><h1>اتصل بنا</h1><p>يسعدنا تواصلك معنا في أي وقت.</p></div>
    <div class="mod-two" style="align-items:start">
        <div class="mod-panel">
            <div class="mod-field"><label>الاسم</label><input class="mod-input" placeholder="اسمك"></div>
            <div class="mod-field"><label>البريد الإلكتروني</label><input class="mod-input" type="email" placeholder="name@example.com"></div>
            <div class="mod-field"><label>الموضوع</label><input class="mod-input" placeholder="موضوع الرسالة"></div>
            <div class="mod-field"><label>الرسالة</label><textarea class="mod-input" rows="5" placeholder="اكتب رسالتك هنا..."></textarea></div>
            <button class="mod-btn" type="button">إرسال الرسالة</button>
        </div>
        <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
            <div class="mod-panel">📍 <strong>العنوان</strong><div style="color:var(--muted)">طريق الملك عبد العزيز، الرياض</div></div>
            <div class="mod-panel">📞 <strong>الهاتف</strong><div style="color:var(--muted)">+966 800 123 4567</div></div>
            <div class="mod-panel">✉ <strong>البريد</strong><div style="color:var(--muted)">support@sellchase.com</div></div>
            <div class="mod-panel">🕒 <strong>ساعات العمل</strong><div style="color:var(--muted)">السبت – الخميس، ٩ص – ٩م</div></div>
            <img src="https://picsum.photos/seed/sc-contact-map/800/450" alt="خريطة الموقع" style="width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:var(--radius-lg)">
        </div>
    </div>
</div>
@endsection

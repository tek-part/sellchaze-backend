@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-legal">
    <aside class="mod-legal__toc">
        <strong style="color:var(--text)">المحتويات</strong>
        <a href="#s1">مقدمة</a><a href="#s2">جمع البيانات</a><a href="#s3">استخدام البيانات</a><a href="#s4">التفاصيل</a><a href="#s5">التواصل</a>
    </aside>
    <div class="mod-legal__body">
        <h1 style="font-size:var(--fs-2xl)">{{ $docTitle }}</h1>
        <p style="color:var(--subtle)">آخر تحديث: ١ يناير ٢٠٢٥</p>
        <h2 id="s1">١. مقدمة</h2><p>نلتزم بحماية حقوقك وخصوصيتك. توضّح هذه الصفحة الشروط التي تحكم استخدامك للمتجر وتعاملك معنا.</p>
        <h2 id="s2">٢. جمع البيانات</h2><p>نجمع المعلومات التي تقدّمها عند إنشاء حساب أو إتمام طلب، مثل الاسم والبريد وعنوان الشحن، لتحسين تجربتك.</p>
        <h2 id="s3">٣. استخدام البيانات</h2><p>نستخدم بياناتك لمعالجة الطلبات، إرسال التحديثات، وتقديم عروض مخصصة. لا نبيع بياناتك لأي طرف ثالث.</p>
        @if($doc === 'shipping')
        <h2 id="s4">مناطق ومدد الشحن</h2>
        <table class="mod-ctable" style="margin-top:var(--sp-3)"><thead><tr><td>المنطقة</td><td>المدة</td><td>التكلفة</td></tr></thead>
        <tbody><tr><th>الرياض</th><td>١–٢ يوم عمل</td><td>مجاني فوق ٥٠٠</td></tr><tr><th>المدن الرئيسية</th><td>٢–٤ أيام عمل</td><td>٢٥ ر.س</td></tr><tr><th>باقي المناطق</th><td>٤–٧ أيام عمل</td><td>٣٥ ر.س</td></tr></tbody></table>
        @elseif($doc === 'returns')
        <h2 id="s4">خطوات الإرجاع</h2>
        <ol style="color:var(--muted);line-height:2.1"><li>سجّل طلب الإرجاع من صفحة الطلب خلال ١٤ يوماً.</li><li>غلّف المنتج بحالته الأصلية مع الملحقات.</li><li>سلّم الشحنة لمندوب الاستلام مجاناً.</li><li>يُعاد المبلغ إلى وسيلة الدفع خلال ٥–٧ أيام عمل.</li></ol>
        @else
        <h2 id="s4">٤. حقوقك</h2><p>يحق لك الوصول إلى بياناتك أو تعديلها أو طلب حذفها في أي وقت من خلال إعدادات حسابك.</p>
        @endif
        <h2 id="s5">٥. التواصل</h2><p>لأي استفسار، تواصل معنا عبر <a href="/contact" style="color:var(--primary)">صفحة الاتصال</a>.</p>
    </div>
</div>
@endsection

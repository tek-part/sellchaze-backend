@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-page">
    <div class="mod-page__head" style="text-align:center"><h1>الأسئلة الشائعة</h1><p>ابحث عن إجابة لسؤالك بسرعة.</p></div>
    <div style="max-width:760px;margin:0 auto">
        <div class="mod-couponbar" style="margin-bottom:var(--sp-6)"><input class="mod-input" placeholder="ابحث في الأسئلة..."><button class="mod-btn" type="button">بحث</button></div>
        @php $faqs = [
            ['كيف أتتبع طلبي؟', 'يمكنك تتبع طلبك من صفحة "طلباتي" في حسابك، أو عبر رابط التتبع المُرسل إلى بريدك الإلكتروني.'],
            ['ما هي سياسة الاسترجاع؟', 'يمكنك استبدال أو إرجاع المنتج خلال ١٤ يوماً من الاستلام بشرط أن يكون بحالته الأصلية.'],
            ['كم تستغرق مدة التوصيل؟', 'عادةً ٢–٥ أيام عمل حسب مدينتك، مع خيار التوصيل السريع خلال ٢٤–٤٨ ساعة.'],
            ['ما طرق الدفع المتاحة؟', 'نقبل مدى، فيزا، ماستركارد، Apple Pay، والدفع عند الاستلام.'],
            ['هل المنتجات أصلية؟', 'نعم، جميع منتجاتنا أصلية ١٠٠٪ ومضمونة من موردين معتمدين.'],
        ]; @endphp
        @foreach($faqs as $f)
        <details class="mod-acc" @if($loop->first)open @endif><summary>{{ $f[0] }} <span>▾</span></summary><div class="mod-acc__body">{{ $f[1] }}<div style="margin-top:var(--sp-3);color:var(--muted);font-size:14px">هل كان هذا مفيداً؟ 👍 👎</div></div></details>
        @endforeach
        <div class="mod-band" style="margin-top:var(--sp-8)"><h2 style="font-size:var(--fs-xl)">لم تجد إجابتك؟</h2><p style="opacity:.9">فريق الدعم جاهز لمساعدتك.</p><a class="mod-btn" href="/contact" style="background:#fff;color:var(--primary);margin-top:var(--sp-3)">تواصل معنا</a></div>
    </div>
</div>
@endsection

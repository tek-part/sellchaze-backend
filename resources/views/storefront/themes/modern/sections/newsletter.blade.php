@php $s = $section['settings'] ?? []; @endphp
<section data-section="newsletter" class="mod-news">
    <h2>{{ $s['title'] ?? 'انضم إلى مجتمعنا' }}</h2>
    <p class="mod-news__sub">{{ $s['subtitle'] ?? 'اشترك واحصل على خصم ١٠٪ على أول طلب + آخر العروض الحصرية.' }}</p>
    <form class="mod-news__form"><input class="mod-input" type="email" placeholder="بريدك الإلكتروني" aria-label="email"><button class="mod-btn" type="button">{{ $s['cta_label'] ?? 'اشترك' }}</button></form>
</section>

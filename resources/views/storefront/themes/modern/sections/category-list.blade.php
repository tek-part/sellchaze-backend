@php $s = $section['settings'] ?? []; $cats = $ctx['data']['categories'] ?? []; @endphp
@if(count($cats))
<section data-section="category-list" class="mod-section">
    <div class="mod-section__head"><h2>{{ $s['title'] ?? 'تسوّق حسب الفئة' }}</h2></div>
    <div class="mod-catgrid">
        @foreach($cats as $c)
            <a class="mod-catcard" href="/categories/{{ $c['slug'] }}">
                <div class="mod-catcard__media">@php $cimg = $c['image_url'] ?? ($c['image'] ?? null); @endphp@if($cimg)<img src="{{ $cimg }}" alt="{{ $c['name'] }}" loading="lazy">@endif</div>
                <span class="mod-catcard__name">{{ $c['name'] }}</span>
                @if(isset($c['products_count']))<span class="mod-catcard__count">{{ $c['products_count'] }} منتج</span>@endif
            </a>
        @endforeach
    </div>
</section>
@endif

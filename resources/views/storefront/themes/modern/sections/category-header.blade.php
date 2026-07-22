@php $c = $ctx['data']['category'] ?? null; @endphp
@if($c)
@php $banner = !empty($c['image_url']); $style = $banner ? "background-image:linear-gradient(90deg,rgba(2,20,16,.72),rgba(2,20,16,.35)),url('".$c['image_url']."')" : ''; @endphp
<section data-section="category-header" class="mod-section mod-cathead{{ $banner ? ' mod-cathead--banner' : '' }}" @if($banner)style="{{ $style }}"@endif>
    <h1>{{ $c['name'] }}</h1>
    @if(!empty($c['description']))<p class="mod-cathead__desc">{{ $c['description'] }}</p>@endif
</section>
@endif

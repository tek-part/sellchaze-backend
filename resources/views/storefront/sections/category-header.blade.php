@php($category = $data['category'] ?? null)
@if($category)
    <section data-section="category-header" style="margin-bottom:16px">
        <h2 style="margin-top:0">{{ $category['name'] }}</h2>
        @if(!empty($category['description']))<p class="muted">{{ $category['description'] }}</p>@endif
    </section>
@endif

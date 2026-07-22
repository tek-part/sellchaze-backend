@php($categories = $data['categories'] ?? [])
@if(count($categories))
    <section data-section="category-list" style="margin-bottom:24px">
        <h3>Categories</h3>
        <div class="grid">
            @foreach($categories as $c)
                <a class="card" href="/categories/{{ $c['slug'] }}">
                    <strong>{{ $c['name'] }}</strong>
                    <div class="muted">{{ $c['products_count'] ?? 0 }} products</div>
                </a>
            @endforeach
        </div>
    </section>
@endif

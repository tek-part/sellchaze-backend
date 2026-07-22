@extends('storefront.layout')

@section('content')
    {{-- Hero --}}
    <section class="card" style="margin-bottom:24px">
        <h2 style="margin-top:0">{{ $home['hero']['title'] }}</h2>
        @if($home['hero']['tagline'])<p class="muted">{{ $home['hero']['tagline'] }}</p>@endif
    </section>

    {{-- Categories --}}
    @if(count($home['categories']))
        <h3>Categories</h3>
        <div class="grid" style="margin-bottom:24px">
            @foreach($home['categories'] as $c)
                <a class="card" href="/categories/{{ $c['slug'] }}">
                    <strong>{{ $c['name'] }}</strong>
                    <div class="muted">{{ $c['products_count'] }} products</div>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Featured products --}}
    <h3>Featured products</h3>
    @if(count($home['featured_products']))
        <div class="grid">
            @foreach($home['featured_products'] as $p)
                <a class="card" href="/products/{{ $p['slug'] }}">
                    <strong>{{ $p['name'] }}</strong>
                    <div class="price">{{ $p['price'] }} {{ $store->currency }}</div>
                </a>
            @endforeach
        </div>
    @else
        <p class="muted">No products yet.</p>
    @endif

    {{-- Featured collections placeholder (Phase 4+) --}}
@endsection

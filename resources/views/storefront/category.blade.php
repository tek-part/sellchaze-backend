@extends('storefront.layout')

@section('content')
    <h2 style="margin-top:0">{{ $category->name }}</h2>
    @if($category->description)<p class="muted">{{ $category->description }}</p>@endif

    @if($products->count())
        <div class="grid">
            @foreach($products as $p)
                <a class="card" href="/products/{{ $p->slug }}">
                    <strong>{{ $p->name }}</strong>
                    <div class="price">{{ $p->price }} {{ $store->currency }}</div>
                </a>
            @endforeach
        </div>
    @else
        <p class="muted">No products in this category yet.</p>
    @endif
@endsection

@extends('storefront.layout')

@section('content')
    <h2 style="margin-top:0">Products</h2>
    @if($products->count())
        <div class="grid">
            @foreach($products as $p)
                <a class="card" href="/products/{{ $p->slug }}">
                    <strong>{{ $p->name }}</strong>
                    <div class="price">{{ $p->price }} {{ $store->currency }}</div>
                    @if($p->category)<div class="muted">{{ $p->category->name }}</div>@endif
                </a>
            @endforeach
        </div>
        <div style="margin-top:16px">{{ $products->links() ?? '' }}</div>
    @else
        <p class="muted">No products yet.</p>
    @endif
@endsection

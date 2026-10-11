@if($designAssets['enabled'] ?? false)
    @foreach($designAssets['assets'] ?? [] as $asset)
        @if($asset['kind'] === 'css')
            <link rel="stylesheet" href="{{ $asset['url'] }}" data-store-design-asset="{{ $asset['id'] }}">
        @else
            <script defer src="{{ $asset['url'] }}" data-store-design-asset="{{ $asset['id'] }}"></script>
        @endif
    @endforeach
@endif

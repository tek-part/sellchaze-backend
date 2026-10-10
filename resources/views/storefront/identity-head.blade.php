@php
    $font = $identity['font_family'] ?? null;
    $font = in_array($font, \App\Services\Stores\StoreFontCatalog::families(), true) ? $font : null;
    $fontUrl = \App\Services\Stores\StoreFontCatalog::stylesheet($font);
    $primary = $identity['primary_color'] ?? null;
    $primary = is_string($primary) && preg_match('/^#[0-9a-f]{6}$/i', $primary) ? $primary : null;
    $fontCss = $font === 'system' ? 'system-ui,Segoe UI,sans-serif' : '"'.$font.'","Cairo",system-ui,sans-serif';
@endphp
@if(!empty($identity['favicon_url']))<link rel="icon" href="{{ $identity['favicon_url'] }}">@endif
@if($fontUrl)<link rel="stylesheet" href="{{ $fontUrl }}">@endif
@if($font || $primary)
<style data-store-identity>
    @if($font)
    body{font-family:{!! $fontCss !!}}
    @endif
    @if($primary)
    a{color:{{ $primary }}}
    @endif
</style>
@endif

@php
    $schema = $jsonLd ?? null;
@endphp
@if($schema)
<script type="application/ld+json">{!! is_string($schema) ? $schema : json_encode($schema) !!}</script>
@endif

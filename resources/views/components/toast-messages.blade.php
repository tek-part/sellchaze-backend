@php
    $messages = [
        'success' => session('success'),
        'error'   => session('error'),
        'warning' => session('warning'),
        'info'    => session('info'),
    ];
@endphp
@if(array_filter($messages))
<script>
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof toastr === 'undefined') return;
        @foreach($messages as $type => $message)
            @if($message)
        toastr.{{ $type }}({!! json_encode(is_array($message) ? implode(' ', $message) : $message) !!});
            @endif
        @endforeach
    });
})();
</script>
@endif

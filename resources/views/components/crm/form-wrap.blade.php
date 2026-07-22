@props(['class' => ''])
<div {{ $attributes->merge(['class' => trim('crm-page dashboard-brand mb-5 ' . $class)]) }}>
    {{ $slot }}
</div>

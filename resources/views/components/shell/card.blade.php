@props(['hover' => false])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-100 bg-white p-5 shadow-sm '.($hover ? 'kg-card-hover' : '')]) }}>
    {{ $slot }}
</div>

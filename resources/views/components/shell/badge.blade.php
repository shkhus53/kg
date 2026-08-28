@props(['tone' => 'gray'])

@php
    $tones = [
        'gray' => 'bg-slate-100 text-slate-600',
        'blue' => 'bg-blue-100 text-blue-700',
        'green' => 'bg-emerald-100 text-emerald-700',
        'orange' => 'bg-orange-100 text-orange-700',
        'purple' => 'bg-violet-100 text-violet-700',
        'red' => 'bg-red-100 text-red-700',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide '.($tones[$tone] ?? $tones['gray'])]) }}>
    {{ $slot }}
</span>

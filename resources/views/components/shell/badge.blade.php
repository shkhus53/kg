@props(['tone' => 'gray', 'dot' => false])

@php
    $tones = [
        'gray' => 'bg-slate-100 text-slate-600',
        'blue' => 'bg-blue-100 text-blue-700',
        'green' => 'bg-emerald-100 text-emerald-700',
        'orange' => 'bg-orange-100 text-orange-700',
        'purple' => 'bg-violet-100 text-violet-700',
        'red' => 'bg-red-100 text-red-700',
    ];
    $dotTones = [
        'gray' => 'bg-slate-400',
        'blue' => 'bg-blue-500',
        'green' => 'bg-emerald-500',
        'orange' => 'bg-orange-500',
        'purple' => 'bg-violet-500',
        'red' => 'bg-red-500',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide '.($tones[$tone] ?? $tones['gray'])]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotTones[$tone] ?? $dotTones['gray'] }}"></span>
    @endif
    {{ $slot }}
</span>

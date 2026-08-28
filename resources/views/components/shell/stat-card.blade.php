@props(['value', 'label', 'tone' => 'blue', 'compact' => false])

@php
    $tones = [
        'blue' => 'bg-blue-50 text-blue-600',
        'green' => 'bg-emerald-50 text-emerald-600',
        'orange' => 'bg-orange-50 text-orange-600',
        'purple' => 'bg-violet-50 text-violet-600',
        'red' => 'bg-red-50 text-red-600',
    ];
    $valueTones = [
        'blue' => 'text-slate-900',
        'green' => 'text-emerald-600',
        'orange' => 'text-orange-500',
        'purple' => 'text-violet-600',
        'red' => 'text-red-600',
    ];
    $iconTone = $tones[$tone] ?? $tones['blue'];
    $valueTone = $valueTones[$tone] ?? $valueTones['blue'];
@endphp

@if ($compact)
    {{-- No icon bubble: used in 3-up grids where the icon leaves too little room for the label at mobile width. --}}
    <div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm']) }}>
        <div class="text-lg font-semibold leading-tight {{ $valueTone }}">{{ $value }}</div>
        <div class="text-xs text-slate-500">{{ $label }}</div>
    </div>
@else
    <div {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-2xl border border-slate-100 bg-white p-3 shadow-sm']) }}>
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $iconTone }}">
            {{ $icon ?? '' }}
        </span>
        <div class="min-w-0">
            <div class="text-lg font-semibold leading-tight text-slate-900">{{ $value }}</div>
            <div class="truncate text-xs text-slate-500">{{ $label }}</div>
        </div>
    </div>
@endif

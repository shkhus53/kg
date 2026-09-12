@props(['value', 'label', 'tone' => 'blue', 'compact' => false, 'clickable' => false])

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
    <div {{ $attributes->merge(['class' => 'kg-card-hover rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm']) }}>
        <div class="text-lg font-semibold leading-tight tabular-nums {{ $valueTone }}" x-data x-init="$el.classList.add('kg-count-pop')">{{ $value }}</div>
        <div class="text-xs text-slate-500">{{ $label }}</div>
    </div>
@else
    <div {{ $attributes->merge(['class' => 'kg-card-hover flex items-center gap-3 rounded-2xl border border-slate-100 bg-white p-3 shadow-sm']) }}>
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $iconTone }}">
            {{ $icon ?? '' }}
        </span>
        <div class="min-w-0 flex-1">
            <div class="text-lg font-semibold leading-tight tabular-nums text-slate-900" x-data x-init="$el.classList.add('kg-count-pop')">{{ $value }}</div>
            <div class="truncate text-xs text-slate-500">{{ $label }}</div>
        </div>
        @if ($clickable)
            <svg class="h-4 w-4 shrink-0 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
        @endif
    </div>
@endif

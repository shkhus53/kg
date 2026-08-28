@props(['tone' => 'primary', 'href' => null, 'type' => 'submit'])

@php
    $tones = [
        'primary' => 'bg-blue-600 text-white hover:bg-blue-700 disabled:bg-blue-300',
        'success' => 'bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-emerald-300',
        'warning' => 'bg-orange-500 text-white hover:bg-orange-600 disabled:bg-orange-300',
        'outline' => 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50',
    ];
    $classes = 'inline-flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold tracking-wide transition disabled:cursor-not-allowed '.($tones[$tone] ?? $tones['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif

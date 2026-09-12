@props(['title', 'description' => null])

<div {{ $attributes->class(['kg-enter flex flex-col items-center gap-3 rounded-2xl border border-dashed border-slate-200 bg-white/60 px-6 py-12 text-center']) }}>
    @isset($icon)
        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 [&>svg]:h-6 [&>svg]:w-6" aria-hidden="true">
            {{ $icon }}
        </span>
    @endisset

    <div>
        <p class="text-sm font-semibold text-slate-700">{{ $title }}</p>
        @if ($description)
            <p class="mt-1 max-w-sm text-xs text-slate-400">{{ $description }}</p>
        @endif
    </div>

    @if ($slot->isNotEmpty())
        <div class="mt-1">{{ $slot }}</div>
    @endif
</div>

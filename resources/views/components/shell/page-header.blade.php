@props(['title', 'subtitle' => null, 'backUrl' => null])

<div class="rounded-b-3xl bg-navy-900 px-5 pb-6 pt-5 text-white">
    <div class="flex items-center gap-3">
        @if ($backUrl)
            <a href="{{ $backUrl }}" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
        @else
            <x-shell.user-menu />
        @endif
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-lg font-semibold">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-0.5 truncate text-sm text-white/70">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="shrink-0">{{ $actions }}</div>
        @endisset
    </div>

    @isset($slot)
        <div class="mt-4">
            {{ $slot }}
        </div>
    @endisset
</div>

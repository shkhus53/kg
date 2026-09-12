@props(['steps', 'current'])

@php $currentIndex = array_search($current, $steps); @endphp

<div class="flex items-center gap-1.5" aria-label="{{ __('Import progress') }}">
    @foreach ($steps as $index => $step)
        @php $done = $index < $currentIndex; $active = $index === $currentIndex; @endphp
        <div class="flex items-center gap-1.5 {{ $index > 0 ? 'flex-1' : '' }}">
            @if ($index > 0)
                <span class="h-px flex-1 {{ $done || $active ? 'bg-blue-400' : 'bg-white/15' }}"></span>
            @endif
            <span class="flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-1 text-[11px] font-semibold {{ $active ? 'bg-white text-navy-900' : ($done ? 'text-blue-300' : 'text-white/50') }}">
                @if ($done)
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                @else
                    <span class="flex h-3.5 w-3.5 items-center justify-center rounded-full {{ $active ? 'bg-navy-900 text-white' : 'bg-white/20' }} text-[9px]">{{ $index + 1 }}</span>
                @endif
                {{ $step }}
            </span>
        </div>
    @endforeach
</div>

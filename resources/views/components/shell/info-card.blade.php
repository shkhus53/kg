<div {{ $attributes->merge(['class' => 'rounded-2xl bg-blue-50 p-4 text-sm text-blue-900']) }}>
    <div class="flex gap-2">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="9" />
            <path stroke-linecap="round" d="M12 11v5m0-8h.01" />
        </svg>
        <div>{{ $slot }}</div>
    </div>
</div>

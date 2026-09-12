{{-- Phase 2/10: live connectivity/sync status — icon + dot + label together,
     never color alone. Updated by resources/js/offline.js's paintBadge()
     via #connectivity-badge; this markup is the no-JS/first-paint fallback
     ("Online" is always a safe default guess pre-JS). --}}
<div id="connectivity-badge" data-state="online" class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold">
    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" data-dot></span>
    <svg data-icon class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
    </svg>
    <span data-label>{{ __('Online') }}</span>
</div>

@php($sessionId = $sessionId ?? null)

{{-- 5 tabs no longer fit an equal-width flex row on a 375px screen without
     clipping the last one unreadably — a horizontally scrollable strip
     keeps every tab reachable and tappable rather than silently cut off. --}}
<div class="-mx-1 flex gap-1 overflow-x-auto rounded-xl bg-white/10 p-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
    @foreach ([
        'overview' => ['label' => 'Overview', 'route' => 'analytics.overview'],
        'departments' => ['label' => 'Departments', 'route' => 'analytics.departments'],
        'insights' => ['label' => 'Insights', 'route' => 'analytics.insights'],
        'planning' => ['label' => 'Planning', 'route' => 'analytics.planning'],
        'exceptions' => ['label' => 'Exceptions', 'route' => 'analytics.exceptions'],
        'alerts' => ['label' => 'Alerts', 'route' => 'analytics.alerts'],
        'summary' => ['label' => 'Summary', 'route' => 'analytics.summary'],
        'trends' => ['label' => 'Trends', 'route' => 'analytics.trends'],
    ] as $key => $tab)
        <a href="{{ route($tab['route'], array_filter(['from' => $from, 'to' => $to, 'session_id' => $sessionId])) }}"
           class="shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-center text-xs font-semibold {{ $active === $key ? 'bg-white text-navy-900' : 'text-white/70' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>

@php($sessionId = $sessionId ?? null)

<div class="flex gap-1 rounded-xl bg-white/10 p-1">
    @foreach ([
        'overview' => ['label' => 'Overview', 'route' => 'analytics.overview'],
        'departments' => ['label' => 'Departments', 'route' => 'analytics.departments'],
        'insights' => ['label' => 'Insights', 'route' => 'analytics.insights'],
    ] as $key => $tab)
        <a href="{{ route($tab['route'], array_filter(['from' => $from, 'to' => $to, 'session_id' => $sessionId])) }}"
           class="flex-1 rounded-lg px-2 py-1.5 text-center text-xs font-semibold {{ $active === $key ? 'bg-white text-navy-900' : 'text-white/70' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>

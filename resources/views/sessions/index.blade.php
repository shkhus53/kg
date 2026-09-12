<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Duty Sessions">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    @can('view_planning')
                        <a href="{{ route('planning.index') }}" aria-label="{{ __('Event Planning') }}" class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4" /></svg>
                        </a>
                    @endcan
                    @if (auth()->user()->canManageSessions())
                        <a href="{{ route('sessions.create') }}" aria-label="{{ __('Create Session') }}" class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        </a>
                    @endif
                </div>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        @if (session('status'))
            <div class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        @foreach (['flash_success' => 'green', 'flash_info' => 'blue', 'flash_warning' => 'orange', 'flash_error' => 'red'] as $key => $tone)
            @if (session($key))
                @php $bg = ['green' => 'bg-emerald-50 text-emerald-700', 'blue' => 'bg-blue-50 text-blue-700', 'orange' => 'bg-orange-50 text-orange-700', 'red' => 'bg-red-50 text-red-700'][$tone]; @endphp
                <div class="rounded-2xl {{ $bg }} p-4 text-sm">{{ session($key) }}</div>
            @endif
        @endforeach

        @if ($sessions->isEmpty())
            <x-shell.empty-state
                title="{{ __('No duty sessions yet') }}"
                description="{{ auth()->user()->canManageSessions() ? __('Create your first session to start scheduling attendance.') : __('Sessions will appear here once created.') }}"
            >
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" /></svg>
                </x-slot:icon>
                @if (auth()->user()->canManageSessions())
                    <x-shell.button tone="primary" href="{{ route('sessions.create') }}">{{ __('Create Session') }}</x-shell.button>
                @endif
            </x-shell.empty-state>
        @else
            <div class="lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 space-y-2 xl:grid-cols-3">
                @foreach ($sessions as $session)
                    @php
                        $scheduled = $session->scheduled_count ?? 0;
                        $present = $session->present_count ?? 0;
                        $pct = $scheduled > 0 ? round(($present / $scheduled) * 100) : null;
                        $isActive = $session->isActive();
                    @endphp
                    <a href="{{ route('sessions.show', $session) }}"
                       class="kg-card-hover kg-tap flex flex-col gap-3 rounded-2xl border bg-white p-4 shadow-sm {{ $isActive ? 'border-emerald-200 ring-1 ring-emerald-100' : 'border-slate-100' }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900">{{ $session->name }}</p>
                                <p class="truncate text-xs text-slate-400">
                                    {{ $session->date->format('d M Y') }}
                                    @if ($session->venue) &middot; {{ $session->venue->name }} @endif
                                    &middot; {{ __('created') }} {{ $session->created_at->toIst()->format('d M Y') }}
                                </p>
                            </div>
                            <x-shell.badge :tone="$session->statusTone()" :dot="$isActive">{{ $session->status }}</x-shell.badge>
                        </div>

                        @if ($pct !== null)
                            <div>
                                <div class="mb-1 flex items-center justify-between text-[11px] text-slate-400">
                                    <span>{{ __('Attendance') }}</span>
                                    <span class="font-medium text-slate-600 tabular-nums">{{ $present }}/{{ $scheduled }}</span>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="kg-progress-fill h-full rounded-full {{ $isActive ? 'bg-emerald-500' : 'bg-slate-300' }}" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>

            <div>{{ $sessions->links() }}</div>
        @endif
    </div>
</x-app-layout>

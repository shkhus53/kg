<x-app-layout>
    <x-slot name="header">
        <div class="bg-navy-900 px-5 pb-6 pt-5 text-white">
            <div class="flex items-center justify-between">
                <x-shell.user-menu />
                <span class="text-sm font-medium text-white/80">{{ __('Dashboard') }}</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9" />
                    </svg>
                </span>
            </div>

            <div class="mt-4">
                <p class="text-sm text-white/70">{{ __('Good') }} {{ now()->hour < 12 ? __('Morning') : (now()->hour < 17 ? __('Afternoon') : __('Evening')) }} 👋</p>
                <h1 class="mt-0.5 text-xl font-semibold">{{ auth()->user()->name }}</h1>
                <p class="text-xs text-white/60">{{ now()->format('l, d M Y') }}</p>
            </div>

            @if ($latestSession)
                <div class="mt-5 rounded-2xl bg-white p-4 text-slate-900 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-slate-400">{{ $latestSession->date->format('d M Y') }}</p>
                            <p class="font-semibold">{{ $latestSession->name }}</p>
                        </div>
                        <x-shell.badge :tone="$latestSession->statusTone()">{{ $latestSession->status }}</x-shell.badge>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <x-shell.stat-card :value="$latestSession->duty_assignments_count" label="Scheduled" tone="blue">
                            <x-slot:icon>
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1m8-4H6a2 2 0 0 0-2 2v16l4-2 4 2 4-2 4 2V6a2 2 0 0 0-2-2Z" /></svg>
                            </x-slot:icon>
                        </x-shell.stat-card>
                        <x-shell.stat-card :value="$latestSession->present_count" label="Present" tone="green">
                            <x-slot:icon>
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                            </x-slot:icon>
                        </x-shell.stat-card>
                        <x-shell.stat-card :value="$latestSession->pending_count" label="Pending" tone="orange">
                            <x-slot:icon>
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 3" /></svg>
                            </x-slot:icon>
                        </x-shell.stat-card>
                        <x-shell.stat-card :value="$latestSession->extra_count" label="Extra Present" tone="purple">
                            <x-slot:icon>
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                            </x-slot:icon>
                        </x-shell.stat-card>
                    </div>

                    @php $pct = $latestSession->duty_assignments_count > 0 ? round(100 * $latestSession->present_count / $latestSession->duty_assignments_count) : 0; @endphp
                    <div class="mt-4">
                        <div class="flex items-center justify-between text-xs text-slate-500">
                            <span>{{ __('Attendance Progress') }}</span>
                            <span class="font-semibold text-slate-700">{{ $pct }}%</span>
                        </div>
                        <div class="mt-1 h-2 rounded-full bg-slate-100">
                            <div class="h-2 rounded-full bg-emerald-500" style="width: {{ $pct }}%"></div>
                        </div>
                        <p class="mt-1 text-right text-xs text-slate-400">{{ $latestSession->present_count }} / {{ $latestSession->duty_assignments_count }}</p>
                    </div>

                    <div class="mt-4">
                        <x-shell.button tone="primary" href="{{ route('attendance.shell.live', $latestSession) }}">
                            {{ __('Continue Attendance') }}
                        </x-shell.button>
                    </div>
                </div>
            @else
                <div class="mt-5 rounded-2xl bg-white p-5 text-center text-slate-500 shadow-sm">
                    <p class="text-sm">{{ __('No duty sessions yet.') }}</p>
                    @if (auth()->user()->canManageSessions())
                        <a href="{{ route('sessions.create') }}" class="mt-2 inline-block text-sm font-semibold text-blue-600 hover:underline">{{ __('Create your first session →') }}</a>
                    @endif
                </div>
            @endif
        </div>
    </x-slot>

    <div class="space-y-5">
        <div>
            <h2 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Quick Actions') }}</h2>
            <div class="grid grid-cols-3 gap-3">
                @if (auth()->user()->canManageSessions())
                    <a href="{{ route('sessions.create') }}" class="flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        </span>
                        <span class="text-xs font-medium text-slate-600">{{ __('Create Session') }}</span>
                    </a>
                @endif

                <a href="{{ route('sessions.index') }}" class="flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" /></svg>
                    </span>
                    <span class="text-xs font-medium text-slate-600">{{ __('Sessions') }}</span>
                </a>

                @if ($latestSession)
                    <a href="{{ route('attendance.shell.list', $latestSession) }}" class="flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-violet-50 text-violet-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" /></svg>
                        </span>
                        <span class="text-xs font-medium text-slate-600">{{ __('Attendance List') }}</span>
                    </a>
                @endif

                <a href="{{ route('analytics.overview') }}" class="flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-orange-50 text-orange-500">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4" /></svg>
                    </span>
                    <span class="text-xs font-medium text-slate-600">{{ __('Analytics') }}</span>
                </a>

                <a href="{{ route('analytics.profile-search') }}" class="flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm-8 8a6 6 0 0 0-6 6h20a6 6 0 0 0-6-6H8Z" /></svg>
                    </span>
                    <span class="text-xs font-medium text-slate-600">{{ __('Khidmatguzars') }}</span>
                </a>

                <a href="{{ route('reports.index') }}" class="flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a1 1 0 0 0 1-1V9l-6-6H7a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1Z" /></svg>
                    </span>
                    <span class="text-xs font-medium text-slate-600">{{ __('Reports') }}</span>
                </a>
            </div>
        </div>

        <div>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-500">{{ __('Recent Sessions') }}</h2>
                <a href="{{ route('sessions.index') }}" class="text-xs font-semibold text-blue-600">{{ __('View All') }}</a>
            </div>

            @if ($recentSessions->isEmpty())
                <x-shell.card class="text-center text-slate-400">{{ __('No duty sessions yet.') }}</x-shell.card>
            @else
                <div class="space-y-2">
                    @foreach ($recentSessions as $session)
                        <a href="{{ route('sessions.show', $session) }}" class="flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900">{{ $session->name }}</p>
                                <p class="text-xs text-slate-400">{{ $session->date->format('d M Y') }}</p>
                            </div>
                            <x-shell.badge :tone="$session->statusTone()">{{ $session->status }}</x-shell.badge>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

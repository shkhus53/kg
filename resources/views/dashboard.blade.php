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
                <p class="text-sm text-white/70">{{ __('Good') }} {{ now()->toIst()->hour < 12 ? __('Morning') : (now()->toIst()->hour < 17 ? __('Afternoon') : __('Evening')) }} 👋</p>
                <h1 class="mt-0.5 text-xl font-semibold">{{ auth()->user()->name }}</h1>

                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span class="flex items-center gap-1.5 text-xs text-white/70">
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" /></svg>
                        {{ now()->toIst()->format('l, d M Y') }}
                    </span>

                    <button type="button" x-init="$el.addEventListener('click', () => $dispatch('open-calendar-modal'))"
                            aria-haspopup="dialog"
                            class="kg-tap flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-1 text-xs font-medium text-white/90 hover:bg-white/15">
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 0 1 8.646 3.646 9.003 9.003 0 0 0 12 21a9.003 9.003 0 0 0 8.354-5.646Z" /></svg>
                        {{ $hijriToday->format() }}
                        <svg class="h-3 w-3 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
                    </button>
                </div>
            </div>

            @if (! empty($todaysMiqaats))
                <div class="kg-enter mt-4 flex items-start gap-3 rounded-2xl bg-white/10 p-3.5">
                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/15">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 0 1 8.646 3.646 9.003 9.003 0 0 0 12 21a9.003 9.003 0 0 0 8.354-5.646Z" /></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-white">{{ $todaysMiqaats[0]['title'] }}</p>
                        @if (count($todaysMiqaats) > 1)
                            <p class="text-xs text-white/60">{{ __('+:count more today', ['count' => count($todaysMiqaats) - 1]) }}</p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($latestSession)
                @php $pct = $latestSession->duty_assignments_count > 0 ? round(100 * $latestSession->present_count / $latestSession->duty_assignments_count) : 0; @endphp
                <div class="kg-enter relative mt-5 overflow-hidden rounded-3xl bg-white p-4 text-slate-900 shadow-lg shadow-navy-900/10 lg:p-6">
                    <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_100%_0%,rgba(37,99,235,0.06),transparent_55%)]"></div>

                    <div class="lg:flex lg:items-center lg:gap-8">
                        {{-- Identity + progress + action: fixed-width on desktop so the stat row gets the freed space, not a stretched single column. --}}
                        <div class="lg:w-72 lg:shrink-0">
                            <div class="flex items-center justify-between lg:block">
                                <div>
                                    <p class="text-xs font-medium text-slate-400">{{ $latestSession->date->format('d M Y') }}</p>
                                    <p class="font-semibold lg:text-lg">{{ $latestSession->name }}</p>
                                </div>
                                <x-shell.badge :tone="$latestSession->statusTone()" dot class="lg:mt-2">{{ $latestSession->status }}</x-shell.badge>
                            </div>

                            <div class="mt-4">
                                <div class="flex items-center justify-between text-xs text-slate-500">
                                    <span>{{ __('Attendance Progress') }}</span>
                                    <span class="font-semibold text-slate-700">{{ $pct }}%</span>
                                </div>
                                <div class="mt-1 h-2 rounded-full bg-slate-100">
                                    <div class="kg-progress-fill h-2 rounded-full bg-gradient-to-r from-emerald-500 to-emerald-400" style="width: {{ $pct }}%"></div>
                                </div>
                                <p class="mt-1 text-right text-xs text-slate-400">{{ $latestSession->present_count }} / {{ $latestSession->duty_assignments_count }}</p>
                            </div>

                            <div class="mt-4 hidden lg:block">
                                <x-shell.button tone="primary" href="{{ route('attendance.shell.live', $latestSession) }}">
                                    {{ __('Continue Attendance') }}
                                </x-shell.button>
                            </div>
                        </div>

                        <div class="mt-4 grid flex-1 grid-cols-2 gap-3 lg:mt-0 lg:grid-cols-4">
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
                    </div>

                    @if ($latestSessionGender)
                        <x-shell.gender-breakdown :breakdown="$latestSessionGender" :rows="['scheduled' => 'Scheduled', 'present' => 'Present']" class="mt-3 rounded-xl bg-slate-50 p-3 lg:mt-4" />
                    @endif

                    <div class="mt-4 lg:hidden">
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
            <div class="grid grid-cols-3 gap-3 lg:grid-cols-6">
                @if (auth()->user()->canManageSessions())
                    <a href="{{ route('sessions.create') }}" class="kg-card-hover flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        </span>
                        <span class="text-xs font-medium text-slate-600">{{ __('Create Session') }}</span>
                    </a>
                @endif

                @can('view_sessions')
                    <a href="{{ route('sessions.index') }}" class="kg-card-hover flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" /></svg>
                        </span>
                        <span class="text-xs font-medium text-slate-600">{{ __('Sessions') }}</span>
                    </a>
                @endcan

                @if ($latestSession)
                    @can('view_attendance_history')
                        <a href="{{ route('attendance.shell.list', $latestSession) }}" class="kg-card-hover flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-violet-50 text-violet-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" /></svg>
                            </span>
                            <span class="text-xs font-medium text-slate-600">{{ __('Attendance List') }}</span>
                        </a>
                    @endcan
                @endif

                @can('view_analytics')
                    <a href="{{ route('analytics.overview') }}" class="kg-card-hover flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-orange-50 text-orange-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4" /></svg>
                        </span>
                        <span class="text-xs font-medium text-slate-600">{{ __('Analytics') }}</span>
                    </a>
                @endcan

                @can('view_directory')
                    <a href="{{ route('analytics.profile-search') }}" class="kg-card-hover flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm-8 8a6 6 0 0 0-6 6h20a6 6 0 0 0-6-6H8Z" /></svg>
                        </span>
                        <span class="text-xs font-medium text-slate-600">{{ __('Khidmatguzars') }}</span>
                    </a>
                @endcan

                @can('view_reports')
                    <a href="{{ route('reports.index') }}" class="kg-card-hover flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a1 1 0 0 0 1-1V9l-6-6H7a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1Z" /></svg>
                        </span>
                        <span class="text-xs font-medium text-slate-600">{{ __('Reports') }}</span>
                    </a>
                @endcan
            </div>
        </div>

        <div>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-500">{{ __('Recent Sessions') }}</h2>
                <a href="{{ route('sessions.index') }}" class="text-xs font-semibold text-blue-600">{{ __('View All') }}</a>
            </div>

            @if ($recentSessions->isEmpty())
                <x-shell.empty-state title="{{ __('No duty sessions yet') }}" />
            @else
                <div class="space-y-2 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 xl:grid-cols-3">
                    @foreach ($recentSessions as $session)
                        <a href="{{ route('sessions.show', $session) }}" class="kg-card-hover flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
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

    {{--
        Merged Hijri + Gregorian calendar modal. One shared Alpine scope
        (window custom events bridge it to the header button in the other
        Blade slot). Month navigation is entirely client-side — the whole
        ±6-month window is embedded once as JSON on page load, so paging
        between months is a plain array index change, never a fetch/reload
        (this project's architecture has no fetch/axios anywhere).
    --}}
    <div
        x-data="{
            open: false,
            monthIndex: {{ $calendarAnchorIndex }},
            anchorIndex: {{ $calendarAnchorIndex }},
            months: {{ Js::from($calendarWindow) }},
            selected: null,
            get month() { return this.months[this.monthIndex]; },
            weekdayLabels: ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
            close() { this.open = false; this.selected = null; },
        }"
        x-on:open-calendar-modal.window="open = true; monthIndex = anchorIndex; selected = null"
        x-on:keydown.escape.window="if (open) close()"
        x-effect="document.body.style.overflow = open ? 'hidden' : ''"
        x-cloak
    >
        <div x-show="open" x-transition.opacity class="fixed inset-0 z-50 bg-slate-900/50" x-on:click="close()" aria-hidden="true"></div>

        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-6 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-6 sm:translate-y-0 sm:scale-95"
             class="fixed inset-x-0 bottom-0 z-50 max-h-[88vh] overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:inset-x-auto sm:left-1/2 sm:top-1/2 sm:bottom-auto sm:w-full sm:max-w-md sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-3xl"
             role="dialog" aria-modal="true" aria-label="{{ __('Calendar') }}"
             x-init="$watch('open', (v) => { if (v) $nextTick(() => $refs.closeBtn?.focus()); })">

            <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-slate-200 sm:hidden"></div>

            <div class="flex items-center justify-between">
                <button type="button" x-on:click="monthIndex = Math.max(0, monthIndex - 1)" :disabled="monthIndex === 0"
                        aria-label="{{ __('Previous month') }}" class="kg-tap flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-50 disabled:opacity-30">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7" /></svg>
                </button>
                <p class="text-sm font-semibold text-slate-900" x-text="month.monthName + ' ' + month.year + 'H'"></p>
                <button type="button" x-on:click="monthIndex = Math.min(months.length - 1, monthIndex + 1)" :disabled="monthIndex === months.length - 1"
                        aria-label="{{ __('Next month') }}" class="kg-tap flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-50 disabled:opacity-30">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                </button>
            </div>

            <div class="mt-4 grid grid-cols-7 gap-1 text-center text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                <template x-for="label in weekdayLabels" :key="label"><span x-text="label"></span></template>
            </div>

            <div class="mt-1 grid grid-cols-7 gap-1">
                <template x-for="(week, wi) in month.weeks" :key="wi">
                    <template x-for="(cell, ci) in week" :key="ci">
                        <button type="button"
                                x-show="cell"
                                x-on:click="cell && cell.miqaats.length ? (selected = cell) : null"
                                :class="cell?.isToday ? 'bg-navy-900 text-white' : (cell?.miqaats?.length ? 'bg-violet-50 text-slate-900 hover:bg-violet-100' : 'text-slate-700 hover:bg-slate-50')"
                                class="kg-tap flex aspect-square flex-col items-center justify-center rounded-xl text-xs">
                            <span x-text="cell?.gregorian?.day" class="font-semibold leading-tight"></span>
                            <span x-text="cell?.hijri?.day" class="text-[9px] leading-tight opacity-70"></span>
                            <span x-show="cell?.miqaats?.length" class="mt-0.5 h-1 w-1 rounded-full" :class="cell?.isToday ? 'bg-white' : 'bg-violet-500'"></span>
                        </button>
                    </template>
                </template>
            </div>

            <div x-show="selected" x-cloak class="mt-4 space-y-1.5 rounded-xl bg-violet-50 p-3">
                <p class="text-xs font-semibold text-slate-500" x-text="selected ? (selected.gregorian.day + '/' + selected.gregorian.month + '/' + selected.gregorian.year + ' · ' + selected.hijri.day + ' ' + month.monthName) : ''"></p>
                <template x-for="m in (selected?.miqaats ?? [])" :key="m.title">
                    <p class="text-sm text-slate-800" x-text="m.title"></p>
                </template>
            </div>

            <button type="button" x-ref="closeBtn" x-on:click="close()" aria-label="{{ __('Close') }}"
                    class="kg-tap absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
    </div>
</x-app-layout>

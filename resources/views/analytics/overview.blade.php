<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Analytics" :back-url="route('dashboard')">
            @include('analytics._tabs', ['active' => 'overview', 'from' => $from, 'to' => $to, 'sessionId' => $sessionId])
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        <x-shell.card class="lg:sticky lg:top-4 lg:z-10">
            <form method="GET" action="{{ route('analytics.overview') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-[repeat(3,minmax(0,1fr))_auto] lg:items-end">
                <div>
                    <x-input-label for="from" :value="__('From')" />
                    <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$from" />
                </div>
                <div>
                    <x-input-label for="to" :value="__('To')" />
                    <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$to" />
                </div>
                <div class="col-span-2 lg:col-span-1">
                    <x-input-label for="session_id" :value="__('Session (optional)')" />
                    <select id="session_id" name="session_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('All sessions in range') }}</option>
                        @foreach ($sessionOptions as $s)
                            <option value="{{ $s->id }}" @selected((string) $sessionId === (string) $s->id)>{{ $s->name }} ({{ $s->date->format('d M Y') }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2 lg:col-span-1">
                    <x-shell.button tone="primary" type="submit">{{ __('Apply') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        @if ($scheduled === 0 && $extra === 0)
            <x-shell.empty-state title="{{ __('No sessions in the selected period') }}" description="{{ __('Try widening the date range or clearing the session filter.') }}">
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4" /></svg>
                </x-slot:icon>
            </x-shell.empty-state>
        @else
            @php
                $drillBase = ['from' => $from, 'to' => $to, 'session_id' => $sessionId, 'department_id' => $departmentId];
            @endphp
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <a href="{{ route('analytics.profile-search', $drillBase) }}">
                    <x-shell.stat-card :value="$scheduled" label="Total Scheduled" tone="blue" clickable>
                        <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1m8-4H6a2 2 0 0 0-2 2v16l4-2 4 2 4-2 4 2V6a2 2 0 0 0-2-2Z" /></svg></x-slot:icon>
                    </x-shell.stat-card>
                </a>
                <a href="{{ route('analytics.profile-search', [...$drillBase, 'status' => 'present']) }}">
                    <x-shell.stat-card :value="$present" label="Present" tone="green" clickable>
                        <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg></x-slot:icon>
                    </x-shell.stat-card>
                </a>
                <a href="{{ route('analytics.profile-search', [...$drillBase, 'status' => 'absent']) }}">
                    <x-shell.stat-card :value="$absent" label="Absent" tone="red" clickable>
                        <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></x-slot:icon>
                    </x-shell.stat-card>
                </a>
                <x-shell.stat-card :value="$extra" label="Extra Present" tone="purple">
                    <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg></x-slot:icon>
                </x-shell.stat-card>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <x-shell.stat-card compact :value="$operatorsActive" label="Operators Active" tone="blue" />
                <x-shell.stat-card compact :value="$corrections" label="Corrections" tone="orange" />
                <x-shell.stat-card compact :value="$reopenedSessions" label="Reopened Sessions" tone="orange" />
                <x-shell.stat-card compact :value="$importsCount" label="Imports" tone="green" />
            </div>

            @if ($pending > 0)
                <x-shell.info-card>
                    <a href="{{ route('analytics.profile-search', [...$drillBase, 'status' => 'pending']) }}" class="underline">
                        {{ __(':count assignment(s) still Pending in this period (active sessions). Not counted as Present or Absent.', ['count' => $pending]) }}
                    </a>
                </x-shell.info-card>
            @endif

            <div class="lg:grid lg:grid-cols-5 lg:gap-3 lg:space-y-0 space-y-3">
                <x-shell.card class="kg-glass relative overflow-hidden lg:col-span-2">
                    <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_right,theme(colors.emerald.100),transparent_60%)]"></div>
                    <p class="text-xs font-medium text-slate-400">{{ __('Attendance Rate') }}</p>
                    <p class="text-4xl font-bold tabular-nums text-emerald-600" x-data x-init="$el.classList.add('kg-count-pop')">{{ $rate !== null ? $rate.'%' : '—' }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ __('Present ÷ Total Scheduled — Extra Present excluded') }}</p>
                </x-shell.card>

                <x-shell.card class="lg:col-span-3">
                    <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Gender Breakdown') }}</h3>
                    <x-shell.gender-breakdown :breakdown="$genderBreakdown" :rows="['scheduled' => 'Scheduled', 'present' => 'Present']" />
                </x-shell.card>
            </div>

            <div class="lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 space-y-5">
                @if ($trend->isNotEmpty())
                    <x-shell.card>
                        <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Attendance Trend') }}</h3>
                        <div class="space-y-2">
                            @foreach ($trend as $point)
                                <div class="flex items-center gap-3">
                                    <div class="w-20 shrink-0 text-xs text-slate-400">{{ \Carbon\Carbon::parse($point->session_date)->format('d M') }}</div>
                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                        <div class="kg-progress-fill h-full rounded-full bg-emerald-500" style="width: {{ $point->rate }}%"></div>
                                    </div>
                                    <div class="w-12 shrink-0 text-right text-xs font-semibold tabular-nums text-slate-700">{{ $point->rate }}%</div>
                                </div>
                            @endforeach
                        </div>
                    </x-shell.card>
                @endif

                @if ($departments->isNotEmpty())
                    <x-shell.card>
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-slate-700">{{ __('Attendance by Department') }}</h3>
                            <a href="{{ route('analytics.departments', ['from' => $from, 'to' => $to, 'session_id' => $sessionId]) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">{{ __('View All') }}</a>
                        </div>
                        <div class="space-y-3">
                            @foreach ($departments as $dept)
                                <a href="{{ route('analytics.profile-search', ['from' => $from, 'to' => $to, 'session_id' => $sessionId, 'department_id' => $dept->department_id]) }}" class="group block rounded-lg -mx-1 px-1 py-0.5 hover:bg-slate-50">
                                    <div class="flex items-center justify-between text-xs text-slate-600">
                                        <span class="flex items-center gap-1">
                                            {{ $dept->department_name }}
                                            <svg class="h-3 w-3 text-slate-300 opacity-0 transition group-hover:opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
                                        </span>
                                        <span class="font-medium tabular-nums">{{ $dept->rate }}%</span>
                                    </div>
                                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div class="kg-progress-fill h-full rounded-full bg-blue-500" style="width: {{ $dept->rate }}%"></div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </x-shell.card>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>

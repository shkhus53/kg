{{--
    Phase 3: Historical Planning Intelligence. Read-only, purely additive to
    the Create Event Session wizard — never mutates historical data, never
    blocks the operator from proceeding manually regardless of what it
    shows. Recommended Scheduled HR is derived FROM the department table
    below it (see ForecastingService::recommend) — the two numbers can
    never disagree by construction.
--}}
@if ($forecast)
<x-shell.card class="kg-enter kg-glass relative overflow-hidden">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_right,theme(colors.blue.50),transparent_60%)]"></div>
    <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Historical Planning Intelligence') }}</h3>

    @if (! $forecast['has_history'])
        <x-shell.empty-state title="{{ __('No historical data available for a reliable recommendation') }}" description="{{ __('This appears to be the first tracked occurrence of this event. Plan staffing based on judgment — the system will learn from its actual attendance for next time.') }}">
            <x-slot:icon>
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4" /></svg>
            </x-slot:icon>
        </x-shell.empty-state>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-shell.stat-card compact :value="$forecast['recommended_scheduled_hr']" label="Recommended HR" tone="blue" />
            <x-shell.stat-card compact :value="$forecast['expected_attendance']" label="Expected Attendance" tone="green" />
            <x-shell.stat-card compact :value="'~'.$forecast['operational_demand']" label="Operational Demand" tone="purple" />
            <div class="kg-card-hover rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                <div class="text-lg font-semibold leading-tight text-slate-900">{{ $forecast['range']['low'] }}–{{ $forecast['range']['high'] }}</div>
                <div class="text-xs text-slate-500">{{ __('Planning Range') }}</div>
            </div>
        </div>

        <div class="mt-3 flex items-center justify-between text-xs">
            <span class="text-slate-400">{{ __('Confidence') }}</span>
            <x-shell.badge :tone="match($forecast['confidence']) { 'High' => 'green', 'Medium' => 'orange', 'Low' => 'gray', default => 'gray' }" dot>{{ $forecast['confidence'] }}</x-shell.badge>
        </div>
        <p class="mt-1 text-[11px] text-slate-400">{{ __('Based on :count comparable historical session(s).', ['count' => $forecast['comparable_count']]) }}</p>

        {{-- Department Planning: the primary planning output. Recommended
             HR above is the sum of these, not the other way around. --}}
        <div class="mt-5">
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Department Planning') }}</h4>
            <div class="space-y-2">
                @foreach ($forecast['departments'] as $dept)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 p-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $dept['name'] }}</p>
                            <div class="mt-0.5 flex flex-wrap items-center gap-1 text-[10px]">
                                <span class="rounded-full px-1.5 py-0.5 font-semibold uppercase tracking-wide
                                    {{ $dept['evidence'] === 'Strong' ? 'bg-emerald-100 text-emerald-700' : ($dept['evidence'] === 'Medium' ? 'bg-orange-100 text-orange-700' : 'bg-slate-100 text-slate-500') }}">
                                    {{ $dept['evidence'] }} {{ __('evidence') }}
                                </span>
                                @if ($dept['extra_present_only'])
                                    <span class="rounded-full bg-violet-100 px-1.5 py-0.5 font-semibold uppercase tracking-wide text-violet-700">{{ __('Extra-present history') }}</span>
                                @endif
                                @if (in_array('consistently_under_planned', $dept['tags'], true))
                                    <span class="rounded-full bg-red-100 px-1.5 py-0.5 font-semibold uppercase tracking-wide text-red-700">{{ __('Under-planned') }}</span>
                                @endif
                                @if (in_array('growing_demand', $dept['tags'], true))
                                    <span class="rounded-full bg-blue-100 px-1.5 py-0.5 font-semibold uppercase tracking-wide text-blue-700">{{ __('Growing') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm font-semibold tabular-nums text-slate-900">{{ $dept['recommended'] }}</p>
                            <p class="text-[10px] text-slate-400">{{ __('att. ~:n', ['n' => $dept['expected_attendance']]) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Progressive disclosure: explanation + raw evidence, collapsed by default. --}}
        <div x-data="{ open: false }" class="mt-4 border-t border-slate-100 pt-3">
            <button type="button" @click="open = !open" class="kg-tap flex w-full items-center justify-between text-left text-xs font-semibold text-blue-600" :aria-expanded="open.toString()">
                {{ __('Why this recommendation?') }}
                <svg class="h-4 w-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" /></svg>
            </button>
            <div x-show="open" x-cloak x-transition class="mt-2 space-y-1.5 text-xs text-slate-600">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($forecast['explanation'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>

                @if (! empty($forecast['planning_gap']['under_planning_pct']) || ! empty($forecast['planning_gap']['extra_dependency_pct']))
                    <div class="mt-2 grid grid-cols-2 gap-2 text-[11px]">
                        @if ($forecast['planning_gap']['under_planning_pct'] !== null)
                            <div><span class="text-slate-400">{{ __('Historical under-planning') }}:</span> {{ $forecast['planning_gap']['under_planning_pct'] }}%</div>
                        @endif
                        @if ($forecast['planning_gap']['extra_dependency_pct'] !== null)
                            <div><span class="text-slate-400">{{ __('Extra Present dependency') }}:</span> {{ $forecast['planning_gap']['extra_dependency_pct'] }}%</div>
                        @endif
                    </div>
                @endif

                <p class="mt-3 mb-1 font-semibold text-slate-500">{{ __('Historical Evidence') }}</p>
                <div class="space-y-1">
                    @foreach ($forecast['evidence_sessions'] as $ev)
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-2 py-1.5">
                            <span>{{ \Illuminate\Support\Carbon::parse($ev['date'])->format('d M Y') }} @if($ev['venue_match']) &middot; {{ __('same venue') }} @endif</span>
                            <span class="tabular-nums">{{ __('demand') }} {{ $ev['demand'] }} ({{ __('sched.') }} {{ $ev['scheduled'] }})</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</x-shell.card>
@endif

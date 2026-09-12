<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Analytics" :back-url="route('dashboard')">
            @include('analytics._tabs', ['active' => 'planning', 'from' => $from, 'to' => $to])
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        <x-shell.card class="lg:sticky lg:top-4 lg:z-10">
            <form method="GET" action="{{ route('analytics.planning') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-[repeat(2,minmax(0,1fr))_auto] lg:items-end">
                <div>
                    <x-input-label for="from" :value="__('From')" />
                    <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$from" />
                </div>
                <div>
                    <x-input-label for="to" :value="__('To')" />
                    <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$to" />
                </div>
                <div class="col-span-2 lg:col-span-1">
                    <x-shell.button tone="primary" type="submit">{{ __('Apply') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        @if (empty($plans))
            <x-shell.empty-state
                title="{{ __('No planning comparison available') }}"
                description="{{ __('Planning comparison requires at least one Duty Session created from an Event Plan within this date range.') }}"
            >
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4" /></svg>
                </x-slot:icon>
            </x-shell.empty-state>
        @else
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-shell.stat-card compact :value="$sum_recommended" label="Recommended" tone="blue" />
                <x-shell.stat-card compact :value="$sum_planned" label="Planned" tone="purple" />
                <x-shell.stat-card compact :value="$sum_actual" label="Actual Assigned" tone="green" />
                <div class="kg-card-hover rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                    <div class="text-lg font-semibold leading-tight tabular-nums {{ $overall_gap < 0 ? 'text-orange-600' : ($overall_gap > 0 ? 'text-blue-600' : 'text-emerald-600') }}">{{ $overall_gap >= 0 ? '+' : '' }}{{ $overall_gap }}</div>
                    <div class="text-xs text-slate-500">{{ __('Overall Gap') }}</div>
                </div>
            </div>

            <x-shell.card class="kg-glass relative overflow-hidden">
                <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_right,theme(colors.blue.50),transparent_60%)]"></div>
                <p class="text-xs font-medium text-slate-400">{{ __('Planning Accuracy') }}</p>
                <p class="text-4xl font-bold tabular-nums text-blue-600">{{ $overall_accuracy !== null ? $overall_accuracy.'%' : '—' }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ __('100 × (1 − |Actual − Planned| ÷ Planned), weighted by Planned across departments. Departments Planned = 0 are excluded here — see Underplanned below.') }}</p>
            </x-shell.card>

            @if ($repeated_underplanned->isNotEmpty())
                <x-shell.card>
                    <h3 class="mb-2 text-sm font-semibold text-red-600">{{ __('Repeatedly Underplanned Departments') }}</h3>
                    <p class="mb-3 text-xs text-slate-400">{{ __('Actual staffing exceeded the plan in 2 or more sessions — a candidate for a higher future Recommended Scheduled HR.') }}</p>
                    <div class="space-y-1.5 text-sm">
                        @foreach ($repeated_underplanned as $dept)
                            <div class="flex items-center justify-between rounded-lg bg-red-50 px-3 py-2">
                                <span class="text-slate-700">{{ $dept['name'] }}</span>
                                <span class="font-semibold text-red-600">{{ $dept['sessions'] }} {{ __('sessions') }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif

            @if ($department_patterns->isNotEmpty())
                <x-shell.card>
                    <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Department Patterns') }}</h3>
                    <p class="mb-3 text-xs text-slate-400">{{ __('Requires at least :n observed sessions in this range before calling anything a pattern — one bad session is an incident, not a pattern.', ['n' => 3]) }}</p>
                    <div class="space-y-1.5 text-sm">
                        @foreach ($department_patterns as $dept)
                            <div class="flex items-center justify-between rounded-lg px-3 py-2 {{ $dept['pattern'] === 'persistently_underplanned' ? 'bg-red-50' : 'bg-slate-50' }}">
                                <span class="truncate text-slate-700">{{ $dept['name'] }}</span>
                                <span class="shrink-0 text-xs tabular-nums text-slate-500">
                                    {{ __('Underplanned') }} {{ $dept['times_underplanned'] }}/{{ $dept['sessions_observed'] }} {{ __('sessions') }}
                                    <span class="font-semibold {{ $dept['pattern'] === 'persistently_underplanned' ? 'text-red-600' : 'text-emerald-600' }}">
                                        &middot; {{ $dept['pattern'] === 'persistently_underplanned' ? __('Persistently Underplanned') : __('Stable') }}
                                    </span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif

            <div class="space-y-3">
                @foreach ($plans as $entry)
                    @php $plan = $entry['plan']; @endphp
                    <x-shell.card>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $plan->event->name }}</p>
                                <p class="text-xs text-slate-400">{{ $plan->planned_date->format('d M Y') }} &middot; {{ $plan->venue->name }}</p>
                            </div>
                            <a href="{{ route('sessions.show', $plan->duty_session_id) }}" class="shrink-0 text-xs font-semibold text-blue-600">{{ __('View Session') }}</a>
                        </div>
                        <div class="space-y-1.5 text-sm">
                            @foreach ($entry['rows'] as $row)
                                <div class="flex items-center justify-between rounded-lg px-3 py-2 {{ $row['underplanned'] ? 'bg-red-50' : 'bg-slate-50' }}">
                                    <span class="truncate text-slate-700">{{ $row['name'] }}</span>
                                    <span class="shrink-0 text-xs tabular-nums text-slate-500">
                                        {{ __('Rec.') }} {{ $row['recommended'] }} &middot; {{ __('Planned') }} {{ $row['planned'] }} &middot; {{ __('Actual') }} {{ $row['actual'] }}
                                        @if ($row['extra'] > 0) &middot; {{ __('Extra') }} {{ $row['extra'] }} @endif
                                        <span class="font-semibold {{ $row['gap'] < 0 ? 'text-orange-600' : ($row['gap'] > 0 ? 'text-blue-600' : 'text-emerald-600') }}">
                                            ({{ $row['gap'] >= 0 ? '+' : '' }}{{ $row['gap'] }})
                                        </span>
                                        @if ($row['accuracy'] !== null)
                                            &middot; {{ round($row['accuracy']) }}%
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </x-shell.card>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Analytics" :back-url="route('dashboard')">
            @include('analytics._tabs', ['active' => 'trends', 'from' => $from, 'to' => $to])
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        <x-shell.card class="lg:sticky lg:top-4 lg:z-10">
            <form method="GET" action="{{ route('analytics.trends') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-[repeat(2,minmax(0,1fr))_auto] lg:items-end">
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

        @php
            $insufficient = fn () => '<p class="text-sm text-slate-500">'.__('Not enough historical data yet — need at least :n distinct dates with data in this range to draw a reliable trend, not just an incident list.', ['n' => $minDates]).'</p>';
        @endphp

        <x-shell.card>
            <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Daily Attendance Rate') }}</h3>
            <p class="mb-3 text-xs text-slate-400">{{ __('Daily aggregate: total Present ÷ total Scheduled for all sessions that day — never the average of individual session percentages.') }}</p>
            @if (! $attendanceSufficient)
                {!! $insufficient() !!}
            @else
                <div class="space-y-2">
                    @foreach ($attendanceByDate as $point)
                        <div class="flex items-center gap-3">
                            <div class="w-20 shrink-0 text-xs text-slate-400">{{ \Illuminate\Support\Carbon::parse($point['date'])->format('d M') }}</div>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <div class="kg-progress-fill h-full rounded-full bg-emerald-500" style="width: {{ $point['rate'] }}%"></div>
                            </div>
                            <div class="w-24 shrink-0 text-right text-xs font-semibold tabular-nums text-slate-700">{{ $point['rate'] }}% ({{ $point['present'] }}/{{ $point['scheduled'] }})</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-shell.card>

        <x-shell.card>
            <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Daily Extra Present') }}</h3>
            <p class="mb-3 text-xs text-slate-400">{{ __('Extra Present recorded per day — never part of any scheduled/attendance denominator.') }}</p>
            @if (! $extraSufficient)
                {!! $insufficient() !!}
            @else
                @php $maxExtra = max(1, $extraByDate->max('extra')); @endphp
                <div class="space-y-2">
                    @foreach ($extraByDate as $point)
                        <div class="flex items-center gap-3">
                            <div class="w-20 shrink-0 text-xs text-slate-400">{{ \Illuminate\Support\Carbon::parse($point['date'])->format('d M') }}</div>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <div class="kg-progress-fill h-full rounded-full bg-violet-500" style="width: {{ round(100 * $point['extra'] / $maxExtra) }}%"></div>
                            </div>
                            <div class="w-10 shrink-0 text-right text-xs font-semibold tabular-nums text-slate-700">{{ $point['extra'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-shell.card>

        <x-shell.card>
            <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Planned vs Actual Over Time') }}</h3>
            <p class="mb-3 text-xs text-slate-400">{{ __('One point per planned date with a finalized Event Plan — Actual counts DutyAssignment rows (never unique ITS).') }}</p>
            @if (! $planningSufficient)
                {!! $insufficient() !!}
            @else
                <div class="space-y-2">
                    @foreach ($planningTrend as $point)
                        <div class="flex items-center justify-between text-sm">
                            <span class="w-20 shrink-0 text-xs text-slate-400">{{ \Illuminate\Support\Carbon::parse($point['date'])->format('d M') }}</span>
                            <span class="tabular-nums text-slate-600">{{ __('Planned') }} {{ $point['planned'] }} &middot; {{ __('Actual') }} {{ $point['actual'] }}
                                <span class="font-semibold {{ $point['gap'] < 0 ? 'text-orange-600' : ($point['gap'] > 0 ? 'text-blue-600' : 'text-emerald-600') }}">({{ $point['gap'] >= 0 ? '+' : '' }}{{ $point['gap'] }})</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-shell.card>

        <x-shell.card>
            <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Session Volume') }}</h3>
            <p class="mb-3 text-xs text-slate-400">{{ __('Number of Duty Sessions per day.') }}</p>
            @if (! $sessionVolumeSufficient)
                {!! $insufficient() !!}
            @else
                @php $maxSessions = max(1, $sessionVolumeByDate->max('sessions')); @endphp
                <div class="space-y-2">
                    @foreach ($sessionVolumeByDate as $point)
                        <div class="flex items-center gap-3">
                            <div class="w-20 shrink-0 text-xs text-slate-400">{{ \Illuminate\Support\Carbon::parse($point['date'])->format('d M') }}</div>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <div class="kg-progress-fill h-full rounded-full bg-blue-500" style="width: {{ round(100 * $point['sessions'] / $maxSessions) }}%"></div>
                            </div>
                            <div class="w-10 shrink-0 text-right text-xs font-semibold tabular-nums text-slate-700">{{ $point['sessions'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-shell.card>
    </div>
</x-app-layout>

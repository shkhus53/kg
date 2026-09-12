<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Analytics" :back-url="route('dashboard')">
            @include('analytics._tabs', ['active' => 'exceptions', 'from' => $from, 'to' => $to])
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        <x-shell.card class="lg:sticky lg:top-4 lg:z-10">
            <form method="GET" action="{{ route('analytics.exceptions') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-[repeat(2,minmax(0,1fr))_auto] lg:items-end">
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
            $totalExceptions = $pendingInActive + $underplannedRows->count() + $highInvalidImports->count() + $repeatedReopens->count() + $highExtraPresentSessions->count();
        @endphp

        @if ($totalExceptions === 0)
            <x-shell.empty-state
                title="{{ __('No exceptions in this period') }}"
                description="{{ __('Every deterministic check below (pending assignments, underplanned departments, invalid imports, repeated reopens, unusual Extra Present) came back clean.') }}"
            >
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                </x-slot:icon>
            </x-shell.empty-state>
        @else
            @if ($pendingInActive > 0)
                <x-shell.card>
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-orange-600">{{ __('Unresolved Pending Assignments') }}</h3>
                            <p class="text-xs text-slate-400">{{ __('Assignments still Pending in currently Active sessions — attendance not yet marked either way.') }}</p>
                        </div>
                        <a href="{{ route('analytics.profile-search', ['status' => 'pending']) }}" class="shrink-0 text-lg font-bold tabular-nums text-orange-600">{{ $pendingInActive }}</a>
                    </div>
                </x-shell.card>
            @endif

            @if ($underplannedRows->isNotEmpty())
                <x-shell.card>
                    <h3 class="mb-2 text-sm font-semibold text-red-600">{{ __('Underplanned Departments') }}</h3>
                    <p class="mb-3 text-xs text-slate-400">{{ __('Actual staffing exceeded Planned — including departments Planned = 0 that still required staff.') }}</p>
                    <div class="space-y-1.5 text-sm">
                        @foreach ($underplannedRows as $row)
                            <a href="{{ route('sessions.show', $row['plan']->duty_session_id) }}" class="flex items-center justify-between rounded-lg bg-red-50 px-3 py-2">
                                <span class="min-w-0 truncate text-slate-700">{{ $row['name'] }} <span class="text-xs text-slate-400">&middot; {{ $row['plan']->event->name }}</span></span>
                                <span class="shrink-0 text-xs font-semibold tabular-nums text-red-600">{{ __('Planned') }} {{ $row['planned'] }} / {{ __('Actual') }} {{ $row['actual'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif

            @if ($highInvalidImports->isNotEmpty())
                <x-shell.card>
                    <h3 class="mb-2 text-sm font-semibold text-red-600">{{ __('Imports With Unusually High Invalid Rows') }}</h3>
                    <p class="mb-3 text-xs text-slate-400">{{ __('20% or more of the file\'s rows were rejected.') }}</p>
                    <div class="space-y-1.5 text-sm">
                        @foreach ($highInvalidImports as $batch)
                            <a href="{{ route('sessions.show', $batch->duty_session_id) }}" class="flex items-center justify-between rounded-lg bg-red-50 px-3 py-2">
                                <span class="min-w-0 truncate text-slate-700">{{ $batch->original_filename }}</span>
                                <span class="shrink-0 text-xs font-semibold tabular-nums text-red-600">{{ $batch->invalid_rows }}/{{ $batch->total_rows }} {{ __('invalid') }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif

            @if ($repeatedReopens->isNotEmpty())
                <x-shell.card>
                    <h3 class="mb-2 text-sm font-semibold text-orange-600">{{ __('Repeatedly Reopened Sessions') }}</h3>
                    <p class="mb-3 text-xs text-slate-400">{{ __('Reopened 2 or more times — historical events remain immutable, this only counts them.') }}</p>
                    <div class="space-y-1.5 text-sm">
                        @foreach ($repeatedReopens as $row)
                            <a href="{{ route('sessions.show', $row->duty_session_id) }}" class="flex items-center justify-between rounded-lg bg-orange-50 px-3 py-2">
                                <span class="min-w-0 truncate text-slate-700">{{ $row->dutySession?->name }}</span>
                                <span class="shrink-0 text-xs font-semibold tabular-nums text-orange-600">{{ $row->reopen_count }} {{ __('reopens') }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif

            @if ($highExtraPresentSessions->isNotEmpty())
                <x-shell.card>
                    <h3 class="mb-2 text-sm font-semibold text-violet-600">{{ __('Unusually High Extra Present') }}</h3>
                    <p class="mb-3 text-xs text-slate-400">{{ __('5 or more Extra Present in one session, or at least 30% of that session\'s Present count.') }}</p>
                    <div class="space-y-1.5 text-sm">
                        @foreach ($highExtraPresentSessions as $row)
                            <a href="{{ route('sessions.show', $row->session_id) }}" class="flex items-center justify-between rounded-lg bg-violet-50 px-3 py-2">
                                <span class="min-w-0 truncate text-slate-700">{{ $row->session_name }} <span class="text-xs text-slate-400">&middot; {{ \Illuminate\Support\Carbon::parse($row->session_date)->format('d M Y') }}</span></span>
                                <span class="shrink-0 text-xs font-semibold tabular-nums text-violet-600">{{ $row->extra_count }} {{ __('extra') }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif
        @endif
    </div>
</x-app-layout>

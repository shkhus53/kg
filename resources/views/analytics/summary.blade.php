<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Analytics" :back-url="route('dashboard')">
            @include('analytics._tabs', ['active' => 'summary', 'from' => $from, 'to' => $to])
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        <x-shell.card class="lg:sticky lg:top-4 lg:z-10">
            <form method="GET" action="{{ route('analytics.summary') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-[repeat(2,minmax(0,1fr))_auto] lg:items-end">
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

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-slate-400">{{ __('Management Summary') }}: {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} &ndash; {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</p>
            <div class="flex gap-2">
                <a href="{{ route('reports.management-summary.pdf', ['from' => $from, 'to' => $to]) }}" class="kg-tap rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('PDF') }}</a>
                <a href="{{ route('reports.management-summary.excel', ['from' => $from, 'to' => $to]) }}" class="kg-tap rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('Excel') }}</a>
            </div>
        </div>

        <section>
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Operations') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <x-shell.stat-card compact :value="$sessions" label="Sessions" tone="blue" />
                <x-shell.stat-card compact :value="$scheduled" label="Scheduled" tone="blue" />
                <x-shell.stat-card compact :value="$present" label="Present" tone="green" />
                <x-shell.stat-card compact :value="$absent" label="Absent" tone="red" />
                <x-shell.stat-card compact :value="$pending" label="Pending" tone="orange" />
                <x-shell.stat-card compact :value="$rate !== null ? $rate.'%' : '—'" label="Attendance Rate" tone="green" />
                <x-shell.stat-card compact :value="$extra" label="Extra Present" tone="purple" />
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Planning') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <x-shell.stat-card compact :value="$planned" label="Planned" tone="purple" />
                <x-shell.stat-card compact :value="$actual" label="Actual Assigned" tone="green" />
                <div class="kg-card-hover rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                    <div class="text-lg font-semibold leading-tight tabular-nums {{ $planningGap > 0 ? 'text-blue-600' : ($planningGap < 0 ? 'text-orange-600' : 'text-emerald-600') }}">{{ $planningGap >= 0 ? '+' : '' }}{{ $planningGap }}</div>
                    <div class="text-xs text-slate-500">{{ __('Planning Gap') }}</div>
                </div>
                <x-shell.stat-card compact :value="$underplannedDepartments" label="Underplanned Depts" tone="orange" />
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Quality') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <x-shell.stat-card compact :value="$imports" label="Imports" tone="blue" />
                <x-shell.stat-card compact :value="$invalidRows" label="Invalid Rows" tone="red" />
                <x-shell.stat-card compact :value="$corrections" label="Corrections" tone="orange" />
                <x-shell.stat-card compact :value="$reopenedSessions" label="Reopened Sessions" tone="orange" />
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Attention') }}</h2>
            <a href="{{ route('analytics.alerts', ['from' => $from, 'to' => $to]) }}" class="kg-card-hover kg-tap flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                <div>
                    <p class="text-sm font-medium text-slate-900">{{ __('Open Alerts') }}</p>
                    <p class="text-xs text-slate-400">{{ __('View the full Alert Center for reasons and links to investigate.') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if ($highAlerts > 0)
                        <x-shell.badge tone="red">{{ $highAlerts }} {{ __('High') }}</x-shell.badge>
                    @endif
                    @if ($mediumAlerts > 0)
                        <x-shell.badge tone="orange">{{ $mediumAlerts }} {{ __('Medium') }}</x-shell.badge>
                    @endif
                    @if ($highAlerts === 0 && $mediumAlerts === 0)
                        <x-shell.badge tone="green">{{ __('Clear') }}</x-shell.badge>
                    @endif
                </div>
            </a>
        </section>
    </div>
</x-app-layout>

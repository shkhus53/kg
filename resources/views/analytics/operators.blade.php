<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Operator Analytics" :back-url="route('dashboard')" />
    </x-slot>

    <div class="space-y-4">
        <x-shell.card>
            <form method="GET" action="{{ route('analytics.operators') }}" class="grid grid-cols-2 gap-3">
                <div>
                    <x-input-label for="from" :value="__('From')" />
                    <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$from" />
                </div>
                <div>
                    <x-input-label for="to" :value="__('To')" />
                    <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$to" />
                </div>
                <div class="col-span-2">
                    <x-shell.button tone="primary" type="submit">{{ __('Apply') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        <p class="text-xs text-slate-400">
            {{ __('Operational workload only — not a ranking.') }}
        </p>

        <div class="grid grid-cols-2 gap-3">
            <x-shell.button tone="primary" href="{{ route('reports.operators.pdf', ['from' => $from, 'to' => $to]) }}">{{ __('Export PDF') }}</x-shell.button>
            <x-shell.button tone="outline" href="{{ route('reports.operators.excel', ['from' => $from, 'to' => $to]) }}">{{ __('Export Excel') }}</x-shell.button>
        </div>

        @if ($operators->isEmpty())
            <x-shell.empty-state title="{{ __('No attendance activity in this period') }}" />
        @else
            <div class="space-y-2">
                @foreach ($operators as $row)
                    <x-shell.card>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ $row['user']->name }}</p>
                                <p class="text-xs text-slate-400">{{ ucfirst($row['user']->role) }}</p>
                            </div>
                            <a href="{{ route('audit.index', ['operator_id' => $row['user']->id]) }}" class="text-xs font-semibold text-blue-600 underline">
                                {{ __('View Activity') }}
                            </a>
                        </div>
                        <div class="mt-3 grid grid-cols-5 gap-2 text-center text-xs">
                            <div><p class="font-semibold text-slate-900">{{ $row['total_actions'] }}</p><p class="text-slate-400">{{ __('Total') }}</p></div>
                            <div><p class="font-semibold text-emerald-600">{{ $row['present_count'] }}</p><p class="text-slate-400">{{ __('Present') }}</p></div>
                            <div><p class="font-semibold text-red-500">{{ $row['absent_count'] }}</p><p class="text-slate-400">{{ __('Absent') }}</p></div>
                            <div><p class="font-semibold text-blue-600">{{ $row['corrections_count'] }}</p><p class="text-slate-400">{{ __('Corrections') }}</p></div>
                            <div><p class="font-semibold text-violet-600">{{ $row['extra_count'] }}</p><p class="text-slate-400">{{ __('Extra') }}</p></div>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-400">
                            {{ __('Last activity') }}: {{ $row['last_activity'] ? \Carbon\Carbon::parse($row['last_activity'], 'UTC')->toIst()->format('d M Y H:i') : '—' }}
                        </p>
                    </x-shell.card>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>

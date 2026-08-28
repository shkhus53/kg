<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Khidmatguzar Report" :subtitle="$khidmatguzar->full_name" :back-url="route('analytics.profile', $khidmatguzar)" />
    </x-slot>

    <div class="space-y-5">
        <div class="grid grid-cols-2 gap-3">
            <x-shell.button tone="primary" href="{{ route('reports.khidmatguzar.pdf', $khidmatguzar) }}">{{ __('Export PDF') }}</x-shell.button>
            <x-shell.button tone="outline" href="{{ route('reports.khidmatguzar.excel', $khidmatguzar) }}">{{ __('Export Excel') }}</x-shell.button>
        </div>

        <x-shell.card>
            <p class="font-semibold text-slate-900">{{ $khidmatguzar->full_name }}</p>
            <p class="text-xs text-slate-400">{{ __('ITS') }}: {{ $khidmatguzar->its_id }} @if($khidmatguzar->jamaat) &middot; {{ $khidmatguzar->jamaat }} @endif</p>
        </x-shell.card>

        @if ($total === 0)
            <x-shell.card class="text-center text-slate-400">{{ __('No scheduled duty history available.') }}</x-shell.card>
        @else
            <div class="grid grid-cols-3 gap-3">
                <x-shell.stat-card compact :value="$total" label="Total Duties" tone="blue" />
                <x-shell.stat-card compact :value="$present" label="Present" tone="green" />
                <x-shell.stat-card compact :value="$absent" label="Absent" tone="red" />
            </div>

            <x-shell.card>
                <p class="text-xs font-medium text-slate-400">{{ __('Attendance Rate') }}</p>
                <p class="text-3xl font-bold text-emerald-600">{{ $rate }}%</p>
                <p class="mt-1 text-xs text-slate-400">{{ __('Extra Present') }}: {{ $extraCount }}</p>
            </x-shell.card>

            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Department Breakdown') }}</h3>
                <div class="space-y-2">
                    @foreach ($departmentBreakdown as $dept)
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-700">{{ $dept->department_name }}</span>
                            <span class="text-slate-500">{{ $dept->duties }} {{ __('duties') }} &middot; {{ $dept->rate }}%</span>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>

            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Duty History') }} ({{ $history->count() }})</h3>
                <div class="max-h-96 space-y-2 overflow-y-auto">
                    @foreach ($history as $a)
                        <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $a->department->name }}</p>
                                <p class="text-xs text-slate-400">{{ $a->dutySession->date->format('d M Y') }} &middot; {{ $a->dutySession->name }}</p>
                            </div>
                            <x-shell.badge :tone="$a->current_status === 'present' ? 'green' : ($a->current_status === 'absent' ? 'red' : 'orange')">{{ $a->current_status }}</x-shell.badge>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>
        @endif

        @if ($extraHistory->isNotEmpty())
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Extra Present History') }} ({{ $extraHistory->count() }})</h3>
                <div class="space-y-2">
                    @foreach ($extraHistory as $e)
                        <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $e->department_name_snapshot }}</p>
                                <p class="text-xs text-slate-400">{{ $e->dutySession->name }} &middot; {{ $e->marked_at->format('d M Y H:i') }}</p>
                            </div>
                            <x-shell.badge tone="purple">{{ __('Extra') }}</x-shell.badge>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>
        @endif
    </div>
</x-app-layout>

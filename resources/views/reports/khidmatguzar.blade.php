<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Khidmatguzar Report" :subtitle="$khidmatguzar->full_name" :back-url="route('analytics.profile', $khidmatguzar)" />
    </x-slot>

    <div class="space-y-5">
        <div class="grid grid-cols-2 gap-3">
            <x-shell.button tone="primary" href="{{ route('reports.khidmatguzar.pdf', $khidmatguzar) }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0-4-4m4 4 4-4M4 20h16" /></svg>
                {{ __('Export PDF') }}
            </x-shell.button>
            <x-shell.button tone="outline" href="{{ route('reports.khidmatguzar.excel', $khidmatguzar) }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0-4-4m4 4 4-4M4 20h16" /></svg>
                {{ __('Export Excel') }}
            </x-shell.button>
        </div>

        <x-shell.card class="kg-glass relative overflow-hidden">
            <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,theme(colors.blue.50),transparent_60%)]"></div>
            <p class="font-semibold text-slate-900">{{ $khidmatguzar->full_name }}</p>
            <p class="text-xs text-slate-400">{{ __('ITS') }}: {{ $khidmatguzar->its_id }} @if($khidmatguzar->jamaat) &middot; {{ $khidmatguzar->jamaat }} @endif</p>
        </x-shell.card>

        @if ($total === 0)
            <x-shell.empty-state title="{{ __('No scheduled duty history available') }}" />
        @else
            <div class="lg:grid lg:grid-cols-3 lg:items-start lg:gap-4 space-y-4 lg:space-y-0">
                <div class="space-y-4 lg:sticky lg:top-4">
                    <div class="grid grid-cols-3 gap-3">
                        <x-shell.stat-card compact :value="$total" label="Total Duties" tone="blue" />
                        <x-shell.stat-card compact :value="$present" label="Present" tone="green" />
                        <x-shell.stat-card compact :value="$absent" label="Absent" tone="red" />
                    </div>

                    <x-shell.card>
                        <p class="text-xs font-medium text-slate-400">{{ __('Attendance Rate') }}</p>
                        <p class="text-3xl font-bold tabular-nums text-emerald-600">{{ $rate }}%</p>
                        <p class="mt-1 text-xs text-slate-400">{{ __('Extra Present') }}: {{ $extraCount }}</p>
                    </x-shell.card>

                    <x-shell.card>
                        <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Department Breakdown') }}</h3>
                        <div class="space-y-3">
                            @foreach ($departmentBreakdown as $dept)
                                <div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-slate-700">{{ $dept->department_name }}</span>
                                        <span class="tabular-nums text-slate-500">{{ $dept->duties }} {{ __('duties') }} &middot; {{ $dept->rate }}%</span>
                                    </div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                        <div class="kg-progress-fill h-full rounded-full bg-blue-500" style="width: {{ $dept->rate }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-shell.card>
                </div>

                <div class="space-y-4 lg:col-span-2">
                    <x-shell.card>
                        <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Duty History') }} ({{ $history->count() }})</h3>
                        <div class="max-h-96 space-y-2 overflow-y-auto">
                            @foreach ($history as $a)
                                <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                                    <div>
                                        <p class="text-sm font-medium text-slate-900">{{ $a->department->name }}</p>
                                        <p class="text-xs text-slate-400">{{ $a->dutySession->date->format('d M Y') }} &middot; {{ $a->dutySession->name }}</p>
                                    </div>
                                    <x-shell.badge :tone="$a->current_status === 'present' ? 'green' : ($a->current_status === 'absent' ? 'red' : 'orange')" dot>{{ $a->current_status }}</x-shell.badge>
                                </div>
                            @endforeach
                        </div>
                    </x-shell.card>

                    @if ($extraHistory->isNotEmpty())
                        <x-shell.card>
                            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Extra Present History') }} ({{ $extraHistory->count() }})</h3>
                            <div class="space-y-2">
                                @foreach ($extraHistory as $e)
                                    <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                                        <div>
                                            <p class="text-sm font-medium text-slate-900">{{ $e->department_name_snapshot }}</p>
                                            <p class="text-xs text-slate-400">{{ $e->dutySession->name }} &middot; {{ $e->marked_at->toIst()->format('d M Y H:i') }}</p>
                                        </div>
                                        <x-shell.badge tone="purple" dot>{{ __('Extra') }}</x-shell.badge>
                                    </div>
                                @endforeach
                            </div>
                        </x-shell.card>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-app-layout>

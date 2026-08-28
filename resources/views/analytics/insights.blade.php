<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Analytics" :back-url="route('analytics.overview', ['from' => $from, 'to' => $to])">
            @include('analytics._tabs', ['active' => 'insights', 'from' => $from, 'to' => $to])
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-3">
        <p class="text-xs text-slate-400">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} – {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</p>

        @if ($totalScheduled === 0)
            <x-shell.card class="text-center text-slate-400">{{ __('No attendance data available for this period.') }}</x-shell.card>
        @else
            <x-shell.card>
                <p class="text-sm text-slate-600">{{ __('Total scheduled assignments in period') }}</p>
                <p class="text-2xl font-bold text-slate-900">{{ $totalScheduled }}</p>
            </x-shell.card>

            <x-shell.card>
                <p class="text-sm text-slate-600">{{ __('Total Extra Present records') }}</p>
                <p class="text-2xl font-bold text-violet-600">{{ $totalExtra }}</p>
            </x-shell.card>

            @if ($bestDepartment)
                <x-shell.card>
                    <p class="text-sm text-slate-600">{{ __('Highest attendance rate department') }}</p>
                    <p class="text-lg font-bold text-emerald-600">{{ $bestDepartment->department_name }} — {{ $bestDepartment->rate }}%</p>
                    <p class="text-xs text-slate-400">{{ __('based on :count scheduled duties', ['count' => $bestDepartment->scheduled]) }}</p>
                </x-shell.card>
            @endif

            @if ($worstDepartment && $worstDepartment->department_name !== ($bestDepartment->department_name ?? null))
                <x-shell.card>
                    <p class="text-sm text-slate-600">{{ __('Lowest attendance rate department') }}</p>
                    <p class="text-lg font-bold text-red-600">{{ $worstDepartment->department_name }} — {{ $worstDepartment->rate }}%</p>
                    <p class="text-xs text-slate-400">{{ __('based on :count scheduled duties', ['count' => $worstDepartment->scheduled]) }}</p>
                </x-shell.card>
            @endif

            @if ($mostActiveSession)
                <x-shell.card>
                    <p class="text-sm text-slate-600">{{ __('Most active duty session') }}</p>
                    <p class="text-lg font-bold text-slate-900">{{ $mostActiveSession->name }}</p>
                    <p class="text-xs text-slate-400">{{ \Carbon\Carbon::parse($mostActiveSession->date)->format('d M Y') }} &middot; {{ $mostActiveSession->scheduled }} {{ __('scheduled') }}</p>
                </x-shell.card>
            @endif

            <x-shell.card>
                <p class="text-sm text-slate-600">{{ __('Khidmatguzars with more than one assignment') }}</p>
                <p class="text-2xl font-bold text-slate-900">{{ $multiAssignmentPeople }}</p>
            </x-shell.card>

            @if ($pendingInActive > 0)
                <x-shell.card>
                    <p class="text-sm text-slate-600">{{ __('Assignments still Pending in active sessions') }}</p>
                    <p class="text-2xl font-bold text-orange-500">{{ $pendingInActive }}</p>
                </x-shell.card>
            @endif
        @endif
    </div>
</x-app-layout>

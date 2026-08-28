<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Analytics" :back-url="route('analytics.overview', ['from' => $from, 'to' => $to])">
            @include('analytics._tabs', ['active' => 'departments', 'from' => $from, 'to' => $to, 'sessionId' => $sessionId])
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-4">
        <p class="text-xs text-slate-400">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} – {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</p>

        @if ($departments->isEmpty())
            <x-shell.card class="text-center text-slate-400">{{ __('No Department data available for this period.') }}</x-shell.card>
        @else
            <div class="space-y-2">
                @foreach ($departments as $dept)
                    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-slate-900">{{ $dept->department_name }}</p>
                            <span class="text-sm font-bold text-emerald-600">{{ $dept->rate }}%</span>
                        </div>
                        <div class="mt-2 grid grid-cols-4 gap-2 text-center text-xs">
                            <div><p class="font-semibold text-slate-900">{{ $dept->scheduled }}</p><p class="text-slate-400">{{ __('Sched.') }}</p></div>
                            <div><p class="font-semibold text-emerald-600">{{ $dept->present }}</p><p class="text-slate-400">{{ __('Present') }}</p></div>
                            <div><p class="font-semibold text-red-500">{{ $dept->absent }}</p><p class="text-slate-400">{{ __('Absent') }}</p></div>
                            <div><p class="font-semibold text-orange-500">{{ $dept->pending }}</p><p class="text-slate-400">{{ __('Pending') }}</p></div>
                        </div>
                        <div class="mt-2 h-2 rounded-full bg-slate-100">
                            <div class="h-2 rounded-full bg-blue-500" style="width: {{ $dept->rate }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Department Report" :back-url="route('reports.index')" />
    </x-slot>

    <div class="space-y-5">
        <x-shell.card>
            <form method="GET" action="{{ route('reports.department') }}" class="grid grid-cols-2 gap-3">
                <div>
                    <x-input-label for="from" :value="__('From')" />
                    <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$from" />
                </div>
                <div>
                    <x-input-label for="to" :value="__('To')" />
                    <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$to" />
                </div>
                <div class="col-span-2">
                    <x-input-label for="session_id" :value="__('Session (optional)')" />
                    <select id="session_id" name="session_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('All sessions in range') }}</option>
                        @foreach ($sessions as $s)
                            <option value="{{ $s->id }}" @selected((string) $sessionId === (string) $s->id)>{{ $s->name }} ({{ $s->date->format('d M Y') }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2">
                    <x-shell.button tone="primary" type="submit">{{ __('Apply') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        <div class="grid grid-cols-2 gap-3">
            <x-shell.button tone="primary" href="{{ route('reports.department.pdf', request()->query()) }}">{{ __('Export PDF') }}</x-shell.button>
            <x-shell.button tone="outline" href="{{ route('reports.department.excel', request()->query()) }}">{{ __('Export Excel') }}</x-shell.button>
        </div>

        @if ($departments->isEmpty())
            <x-shell.card class="text-center text-slate-400">{{ __('No Department data available for this scope.') }}</x-shell.card>
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
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>

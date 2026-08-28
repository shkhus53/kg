<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Session Report" :subtitle="$dutySession->name.' · '.$dutySession->date->format('d M Y')" :back-url="route('reports.index')">
            <x-slot:actions>
                <x-shell.badge :tone="$dutySession->statusTone()">{{ $dutySession->status }}</x-shell.badge>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        @unless ($dutySession->isClosed())
            <x-shell.info-card>
                {{ __('This session is :status — figures reflect current, not final, state.', ['status' => $dutySession->status]) }}
            </x-shell.info-card>
        @endunless

        <div class="grid grid-cols-2 gap-3">
            <x-shell.button tone="primary" href="{{ route('reports.session.pdf', $dutySession) }}">{{ __('Export PDF') }}</x-shell.button>
            <x-shell.button tone="outline" href="{{ route('reports.session.excel', $dutySession) }}">{{ __('Export Excel') }}</x-shell.button>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <x-shell.stat-card :value="$scheduled" label="Scheduled" tone="blue">
                <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1m8-4H6a2 2 0 0 0-2 2v16l4-2 4 2 4-2 4 2V6a2 2 0 0 0-2-2Z" /></svg></x-slot:icon>
            </x-shell.stat-card>
            <x-shell.stat-card :value="$present" label="Present" tone="green">
                <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg></x-slot:icon>
            </x-shell.stat-card>
            <x-shell.stat-card :value="$absent" label="Absent" tone="red">
                <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></x-slot:icon>
            </x-shell.stat-card>
            <x-shell.stat-card :value="$pending" label="Pending" tone="orange">
                <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 3" /></svg></x-slot:icon>
            </x-shell.stat-card>
        </div>

        <x-shell.card>
            <p class="text-xs font-medium text-slate-400">{{ __('Attendance Rate') }}</p>
            <p class="text-3xl font-bold text-emerald-600">{{ $rate !== null ? $rate.'%' : '—' }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ __('Extra Present') }}: {{ $extraCount }}</p>
        </x-shell.card>

        <x-shell.card>
            <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Gender Breakdown') }}</h3>
            <x-shell.gender-breakdown :breakdown="$genderBreakdown" />
        </x-shell.card>

        @if ($departments->isNotEmpty())
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Department Summary') }}</h3>
                <div class="space-y-3">
                    @foreach ($departments as $dept)
                        <div>
                            <div class="flex justify-between text-sm">
                                <span class="text-slate-700">{{ $dept->department_name }}</span>
                                <span class="text-slate-500">{{ $dept->scheduled }} {{ __('sched.') }} &middot; {{ $dept->rate }}%</span>
                            </div>
                            <p class="mt-0.5 text-[11px] text-slate-400">
                                <span class="text-blue-600">{{ __('M') }} {{ $dept->genderBreakdown['scheduled']['male'] }}</span>
                                &middot; <span class="text-violet-600">{{ __('F') }} {{ $dept->genderBreakdown['scheduled']['female'] }}</span>
                                @if ($dept->genderBreakdown['scheduled']['unknown'] > 0)
                                    &middot; <span>{{ __('U') }} {{ $dept->genderBreakdown['scheduled']['unknown'] }}</span>
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>
        @endif

        <x-shell.card>
            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Attendance Detail') }} ({{ $assignments->count() }})</h3>
            <div class="max-h-96 space-y-2 overflow-y-auto">
                @foreach ($assignments as $a)
                    <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $a->full_name_snapshot }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $a->khidmatguzar->its_id }} &middot; {{ $a->department->name }} @if($a->seat) &middot; {{ __('Seat') }} {{ $a->seat }} @endif &middot; {{ \App\Support\Gender::shortLabel($a->gender_snapshot) }}</p>
                        </div>
                        <x-shell.badge :tone="$a->current_status === 'present' ? 'green' : ($a->current_status === 'absent' ? 'red' : 'orange')">{{ $a->current_status }}</x-shell.badge>
                    </div>
                @endforeach
            </div>
        </x-shell.card>

        @if ($extraPresents->isNotEmpty())
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Extra Present') }} ({{ $extraPresents->count() }})</h3>
                <div class="space-y-2">
                    @foreach ($extraPresents as $e)
                        <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $e->full_name_snapshot }}</p>
                                <p class="text-xs text-slate-400">{{ $e->its_id_snapshot }} &middot; {{ $e->department_name_snapshot }}</p>
                            </div>
                            <x-shell.badge tone="purple">{{ __('Extra') }}</x-shell.badge>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>
        @endif
    </div>
</x-app-layout>

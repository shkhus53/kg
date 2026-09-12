<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Department Report" :back-url="route('reports.department')" />
    </x-slot>

    <div class="space-y-5">
        <x-shell.card>
            <form method="GET" action="{{ route('reports.department') }}" class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <x-input-label for="department_id" :value="__('Department')" />
                    <select id="department_id" name="department_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('All Departments') }}</option>
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" @selected((string) $departmentId === (string) $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
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
            <x-shell.button tone="primary" href="{{ route('reports.department.pdf', request()->query()) }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0-4-4m4 4 4-4M4 20h16" /></svg>
                {{ __('Export PDF') }}
            </x-shell.button>
            <x-shell.button tone="outline" href="{{ route('reports.department.excel', request()->query()) }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0-4-4m4 4 4-4M4 20h16" /></svg>
                {{ __('Export Excel') }}
            </x-shell.button>
        </div>

        @if ($sections->isEmpty())
            <x-shell.empty-state title="{{ __('No Department data available for this scope') }}" />
        @endif

        @foreach ($sections as $section)
            <div class="lg:grid lg:grid-cols-5 lg:gap-3 lg:space-y-0 space-y-5">
                <x-shell.card class="lg:col-span-2">
                    <h2 class="mb-3 text-base font-semibold text-slate-900">{{ $section['department']->name }}</h2>

                    <div class="grid grid-cols-2 gap-3">
                        <x-shell.stat-card compact :value="$section['scheduled']" label="Scheduled" tone="blue" />
                        <x-shell.stat-card compact :value="$section['present']" label="Present" tone="green" />
                        <x-shell.stat-card compact :value="$section['absent']" label="Absent" tone="red" />
                        <x-shell.stat-card compact :value="$section['pending']" label="Pending" tone="orange" />
                    </div>

                    <div class="mt-3 text-center">
                        <p class="text-xs font-medium text-slate-400">{{ __('Attendance Rate') }}</p>
                        <p class="text-2xl font-bold tabular-nums text-emerald-600">{{ $section['rate'] !== null ? $section['rate'].'%' : '—' }}</p>
                        <p class="text-xs text-slate-400">{{ __('Extra Present') }}: {{ $section['extraCount'] }}</p>
                    </div>

                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <x-shell.gender-breakdown :breakdown="$section['genderBreakdown']" />
                    </div>
                </x-shell.card>

                <div class="space-y-3 lg:col-span-3">
                    <x-shell.card>
                        <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Detailed Attendance') }} ({{ $section['assignments']->count() }})</h3>
                        @if ($section['assignments']->isEmpty())
                            <x-shell.empty-state title="{{ __('No assignments') }}" />
                        @else
                            <div class="max-h-96 space-y-2 overflow-y-auto">
                                @foreach ($section['assignments'] as $a)
                                    <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-slate-900">{{ $a->full_name_snapshot }}</p>
                                            <p class="truncate text-xs text-slate-400">{{ $a->khidmatguzar->its_id }} &middot; {{ \App\Support\Gender::shortLabel($a->gender_snapshot) }} @if($a->seat) &middot; {{ __('Seat') }} {{ $a->seat }} @endif &middot; {{ $a->dutySession->name }}</p>
                                        </div>
                                        <x-shell.badge :tone="$a->current_status === 'present' ? 'green' : ($a->current_status === 'absent' ? 'red' : 'orange')" dot>{{ $a->current_status }}</x-shell.badge>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </x-shell.card>

                    @if ($section['extraPresents']->isNotEmpty())
                        <x-shell.card>
                            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Extra Present') }} ({{ $section['extraPresents']->count() }})</h3>
                            <div class="space-y-2">
                                @foreach ($section['extraPresents'] as $e)
                                    <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                                        <div>
                                            <p class="text-sm font-medium text-slate-900">{{ $e->full_name_snapshot }}</p>
                                            <p class="text-xs text-slate-400">{{ $e->its_id_snapshot }} &middot; {{ $e->marked_at->toIst()->format('d M Y H:i') }}</p>
                                        </div>
                                        <x-shell.badge tone="purple" dot>{{ __('Extra') }}</x-shell.badge>
                                    </div>
                                @endforeach
                            </div>
                        </x-shell.card>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>

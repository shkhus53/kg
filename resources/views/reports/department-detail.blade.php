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
            <x-shell.button tone="primary" href="{{ route('reports.department.pdf', request()->query()) }}">{{ __('Export PDF') }}</x-shell.button>
            <x-shell.button tone="outline" href="{{ route('reports.department.excel', request()->query()) }}">{{ __('Export Excel') }}</x-shell.button>
        </div>

        @foreach ($sections as $section)
            <x-shell.card>
                <h2 class="mb-3 text-base font-semibold text-slate-900">{{ $section['department']->name }}</h2>

                <div class="grid grid-cols-2 gap-3">
                    <x-shell.stat-card compact :value="$section['scheduled']" label="Scheduled" tone="blue" />
                    <x-shell.stat-card compact :value="$section['present']" label="Present" tone="green" />
                    <x-shell.stat-card compact :value="$section['absent']" label="Absent" tone="red" />
                    <x-shell.stat-card compact :value="$section['pending']" label="Pending" tone="orange" />
                </div>

                <div class="mt-3 text-center">
                    <p class="text-xs font-medium text-slate-400">{{ __('Attendance Rate') }}</p>
                    <p class="text-2xl font-bold text-emerald-600">{{ $section['rate'] !== null ? $section['rate'].'%' : '—' }}</p>
                    <p class="text-xs text-slate-400">{{ __('Extra Present') }}: {{ $section['extraCount'] }}</p>
                </div>

                <div class="mt-4 border-t border-slate-100 pt-3">
                    <x-shell.gender-breakdown :breakdown="$section['genderBreakdown']" />
                </div>
            </x-shell.card>

            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Detailed Attendance') }} ({{ $section['assignments']->count() }})</h3>
                <div class="max-h-96 space-y-2 overflow-y-auto">
                    @foreach ($section['assignments'] as $a)
                        <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $a->full_name_snapshot }}</p>
                                <p class="truncate text-xs text-slate-400">{{ $a->khidmatguzar->its_id }} &middot; {{ \App\Support\Gender::shortLabel($a->gender_snapshot) }} @if($a->seat) &middot; {{ __('Seat') }} {{ $a->seat }} @endif &middot; {{ $a->dutySession->name }}</p>
                            </div>
                            <x-shell.badge :tone="$a->current_status === 'present' ? 'green' : ($a->current_status === 'absent' ? 'red' : 'orange')">{{ $a->current_status }}</x-shell.badge>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>

            @if ($section['extraPresents']->isNotEmpty())
                <x-shell.card>
                    <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Extra Present') }} ({{ $section['extraPresents']->count() }})</h3>
                    <div class="space-y-2">
                        @foreach ($section['extraPresents'] as $e)
                            <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                                <div>
                                    <p class="text-sm font-medium text-slate-900">{{ $e->full_name_snapshot }}</p>
                                    <p class="text-xs text-slate-400">{{ $e->its_id_snapshot }} &middot; {{ $e->marked_at->format('d M Y H:i') }}</p>
                                </div>
                                <x-shell.badge tone="purple">{{ __('Extra') }}</x-shell.badge>
                            </div>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif
        @endforeach
    </div>
</x-app-layout>

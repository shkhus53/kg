<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Report Builder" :back-url="route('reports.index')" />
    </x-slot>

    <div class="space-y-5">
        <x-shell.card>
            <form method="GET" action="{{ route('reports.builder') }}" class="space-y-4">
                <div>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Date & Session') }}</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div>
                            <x-input-label for="from" :value="__('From')" />
                            <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$filters['from'] ?? ''" />
                        </div>
                        <div>
                            <x-input-label for="to" :value="__('To')" />
                            <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$filters['to'] ?? ''" />
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <x-input-label for="session_id" :value="__('Session')" />
                            <select id="session_id" name="session_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">{{ __('Any session') }}</option>
                                @foreach ($sessionOptions as $s)
                                    <option value="{{ $s->id }}" @selected((string) ($filters['session_id'] ?? '') === (string) $s->id)>{{ $s->name }} ({{ $s->date->format('d M Y') }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Department & Operator') }}</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="department_id" :value="__('Department')" />
                            <select id="department_id" name="department_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">{{ __('Any department') }}</option>
                                @foreach ($departmentOptions as $d)
                                    <option value="{{ $d->id }}" @selected((string) ($filters['department_id'] ?? '') === (string) $d->id)>{{ $d->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="operator_id" :value="__('Marked By')" />
                            <select id="operator_id" name="operator_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">{{ __('Any operator') }}</option>
                                @foreach ($operatorOptions as $o)
                                    <option value="{{ $o->id }}" @selected((string) ($filters['operator_id'] ?? '') === (string) $o->id)>{{ $o->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Attendance Status') }}</h3>
                    <select id="status" name="status" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('Any status') }}</option>
                        @foreach (['present' => 'Present', 'absent' => 'Absent', 'pending' => 'Pending'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-3">
                    <x-shell.button tone="primary" type="submit">{{ __('Apply Filters') }}</x-shell.button>
                    @if (! empty($filters))
                        <x-shell.button tone="outline" href="{{ route('reports.builder') }}">{{ __('Clear Filters') }}</x-shell.button>
                    @endif
                </div>
            </form>
        </x-shell.card>

        @if (! empty($filters))
            <div class="flex flex-wrap gap-1.5 text-xs">
                @foreach ($filters as $key => $value)
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">{{ str_replace('_', ' ', $key) }}: <strong>{{ $value }}</strong></span>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <x-shell.stat-card compact :value="$totals['scheduled']" label="Scheduled" tone="blue" />
            <x-shell.stat-card compact :value="$totals['present']" label="Present" tone="green" />
            <x-shell.stat-card compact :value="$totals['absent']" label="Absent" tone="red" />
            <x-shell.stat-card compact :value="$totals['pending']" label="Pending" tone="orange" />
            <x-shell.stat-card compact :value="$totals['rate'] !== null ? $totals['rate'].'%' : '—'" label="Rate" tone="green" />
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('reports.builder.pdf', $filters) }}" class="kg-tap rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('PDF') }}</a>
            <a href="{{ route('reports.builder.excel', $filters) }}" class="kg-tap rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('Excel') }}</a>
        </div>

        <x-shell.card>
            @if ($results->isEmpty())
                <x-shell.empty-state title="{{ __('No assignments match these filters') }}" description="{{ __('Try widening the date range or clearing a filter.') }}" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-xs text-slate-400">
                                <th class="py-2 pr-3">{{ __('ITS') }}</th>
                                <th class="py-2 pr-3">{{ __('Name') }}</th>
                                <th class="py-2 pr-3">{{ __('Department') }}</th>
                                <th class="py-2 pr-3">{{ __('Session') }}</th>
                                <th class="py-2 pr-3">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($results as $row)
                                <tr class="border-b border-slate-50">
                                    <td class="py-2 pr-3 tabular-nums text-slate-600">{{ $row->khidmatguzar?->its_id }}</td>
                                    <td class="py-2 pr-3 text-slate-900">{{ $row->khidmatguzar?->full_name ?? $row->full_name_snapshot }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ $row->department?->name }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ $row->dutySession?->name }} <span class="text-xs text-slate-400">({{ $row->dutySession?->date?->format('d M Y') }})</span></td>
                                    <td class="py-2 pr-3">
                                        <x-shell.badge :tone="match($row->current_status) { 'present' => 'green', 'absent' => 'red', default => 'orange' }">{{ ucfirst($row->current_status) }}</x-shell.badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $results->links() }}</div>
            @endif
        </x-shell.card>
    </div>
</x-app-layout>

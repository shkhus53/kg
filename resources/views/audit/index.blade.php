<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Audit Log" :back-url="route('dashboard')" />
    </x-slot>

    <div class="space-y-5">
        <x-shell.card class="lg:sticky lg:top-4 lg:z-10">
            <form method="GET" action="{{ route('audit.index') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                <div class="col-span-2 lg:col-span-2">
                    <x-input-label for="q" :value="__('ITS or Name')" />
                    <x-text-input id="q" name="q" type="text" class="mt-1 block w-full" :value="$filters['q']" placeholder="{{ __('Search…') }}" />
                </div>

                <div>
                    <x-input-label :value="__('Session')" />
                    <select name="session_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($sessions as $session)
                            <option value="{{ $session->id }}" @selected($filters['session_id'] == $session->id)>{{ $session->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-input-label :value="__('Department')" />
                    <select name="department_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->id }}" @selected($filters['department_id'] == $dept->id)>{{ $dept->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-input-label :value="__('Operator')" />
                    <select name="operator_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}" @selected($filters['operator_id'] == $operator->id)>{{ $operator->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-input-label :value="__('Action')" />
                    <select name="action" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('All') }}</option>
                        <option value="present" @selected($filters['action'] === 'present')>{{ __('Present') }}</option>
                        <option value="absent" @selected($filters['action'] === 'absent')>{{ __('Absent') }}</option>
                        <option value="session_reopened" @selected($filters['action'] === 'session_reopened')>{{ __('Session Reopened') }}</option>
                        <option value="sync_issue" @selected($filters['action'] === 'sync_issue')>{{ __('Offline Sync Issue') }}</option>
                        <option value="import" @selected($filters['action'] === 'import')>{{ __('Import') }}</option>
                        <option value="master_data_change" @selected($filters['action'] === 'master_data_change')>{{ __('Master Data Change') }}</option>
                    </select>
                </div>

                <div>
                    <x-input-label for="date_from" :value="__('From')" />
                    <x-text-input id="date_from" name="date_from" type="date" class="mt-1 block w-full" :value="$filters['date_from']" />
                </div>
                <div>
                    <x-input-label for="date_to" :value="__('To')" />
                    <x-text-input id="date_to" name="date_to" type="date" class="mt-1 block w-full" :value="$filters['date_to']" />
                </div>

                <div class="col-span-2 lg:col-span-2 lg:self-end">
                    <x-shell.button tone="primary" type="submit">{{ __('Apply Filters') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        @if ($events->isEmpty())
            <x-shell.empty-state title="{{ __('No matching events') }}" description="{{ __('Try clearing a filter or widening the date range.') }}">
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l4.414 4.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z" /></svg>
                </x-slot:icon>
            </x-shell.empty-state>
        @else
            @php
                $eventVisuals = [
                    'present' => ['tone' => 'green', 'bg' => 'bg-emerald-50', 'ring' => 'ring-emerald-100', 'text' => 'text-emerald-600', 'icon' => 'M5 13l4 4L19 7'],
                    'absent' => ['tone' => 'red', 'bg' => 'bg-red-50', 'ring' => 'ring-red-100', 'text' => 'text-red-600', 'icon' => 'M6 18L18 6M6 6l12 12'],
                    'session_reopened' => ['tone' => 'orange', 'bg' => 'bg-orange-50', 'ring' => 'ring-orange-100', 'text' => 'text-orange-600', 'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
                    'sync_issue' => ['tone' => 'purple', 'bg' => 'bg-violet-50', 'ring' => 'ring-violet-100', 'text' => 'text-violet-600', 'icon' => 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-8.25 3.75h.008v.008h-.008v-.008z'],
                    'import' => ['tone' => 'blue', 'bg' => 'bg-blue-50', 'ring' => 'ring-blue-100', 'text' => 'text-blue-600', 'icon' => 'M7 16a4 4 0 01-.88-7.9A5.5 5.5 0 0117 8a4.5 4.5 0 01.5 9H7Zm5-4v6m0-6l-2.5 2.5'],
                    'master_data_change' => ['tone' => 'gray', 'bg' => 'bg-slate-100', 'ring' => 'ring-slate-200', 'text' => 'text-slate-600', 'icon' => 'M3 21h18M5 21V7l8-4 8 4v14M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h1m4 0h1'],
                ];
                $defaultVisual = ['tone' => 'gray', 'bg' => 'bg-slate-50', 'ring' => 'ring-slate-100', 'text' => 'text-slate-500', 'icon' => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'];
            @endphp
            <div class="space-y-2 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 xl:grid-cols-3">
                @foreach ($events as $event)
                    @php $visual = $eventVisuals[$event['type']] ?? $defaultVisual; @endphp
                    <div class="kg-card-hover flex gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $visual['bg'] }} ring-4 {{ $visual['ring'] }}" aria-hidden="true">
                            <svg class="h-4 w-4 {{ $visual['text'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $visual['icon'] }}" /></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-medium text-slate-900">{{ $event['description'] }}</p>
                                <x-shell.badge :tone="$visual['tone']">{{ str_replace('_', ' ', $event['type']) }}</x-shell.badge>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                {{ $event['timestamp']->toIst()->format('d M Y H:i') }}
                                &middot; {{ __('Session') }}: {{ $event['session'] }}
                                @if ($event['department'])
                                    &middot; {{ __('Dept') }}: {{ $event['department'] }}
                                @endif
                                &middot; {{ __('By') }}: {{ $event['actor'] }}
                            </p>
                            @if ($event['remark'])
                                <p class="mt-1 text-xs text-slate-500">{{ __('Remark') }}: {{ $event['remark'] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($totalPages > 1)
                <div class="flex items-center justify-between text-sm text-slate-500">
                    @if ($page > 1)
                        <a href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ __('Previous') }}</a>
                    @else
                        <span></span>
                    @endif
                    <span>{{ __('Page :page of :total', ['page' => $page, 'total' => $totalPages]) }}</span>
                    @if ($page < $totalPages)
                        <a href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ __('Next') }}</a>
                    @else
                        <span></span>
                    @endif
                </div>
            @endif
        @endif
    </div>
</x-app-layout>

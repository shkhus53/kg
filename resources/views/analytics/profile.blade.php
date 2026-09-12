<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Khidmatguzar Profile" :back-url="route('analytics.profile-search')">
            <x-slot:actions>
                <a href="{{ route('reports.khidmatguzar', $khidmatguzar) }}" class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold">{{ __('Report') }}</a>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-4">
        <x-shell.breadcrumb :items="[
            ['label' => 'Directory', 'url' => route('analytics.profile-search')],
            ['label' => $khidmatguzar->full_name, 'url' => null],
        ]" />

        <x-shell.card class="kg-glass relative overflow-hidden">
            <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,theme(colors.blue.50),transparent_60%)]"></div>
            <div class="flex items-center gap-3">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-navy-900 text-lg font-semibold text-white">
                    {{ mb_substr($khidmatguzar->full_name, 0, 1) }}
                </span>
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-slate-900">{{ $khidmatguzar->full_name }}</p>
                    <p class="text-xs text-slate-400">{{ __('ITS') }}: {{ $khidmatguzar->its_id }}@if ($khidmatguzar->jamaat) &middot; {{ $khidmatguzar->jamaat }} @endif</p>
                    @if ($total > 0)
                        <p class="mt-1 text-xs font-medium text-emerald-600">{{ $rate }}% {{ __('attendance rate') }}</p>
                    @endif
                </div>
            </div>
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
                        @if ($pending > 0)
                            <p class="mt-1 text-xs text-orange-500">{{ __(':count still Pending (excluded from rate).', ['count' => $pending]) }}</p>
                        @endif
                    </x-shell.card>

                    <x-shell.card>
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <div><dt class="text-slate-400">{{ __('Sessions Served') }}</dt><dd class="font-semibold text-slate-900">{{ $sessionsServed }}</dd></div>
                            <div><dt class="text-slate-400">{{ __('Departments Served') }}</dt><dd class="font-semibold text-slate-900">{{ $departmentsServed }}</dd></div>
                            <div><dt class="text-slate-400">{{ __('First Duty') }}</dt><dd class="font-semibold text-slate-900">{{ $firstDuty ? \Carbon\Carbon::parse($firstDuty)->format('d M Y') : '—' }}</dd></div>
                            <div><dt class="text-slate-400">{{ __('Last Duty') }}</dt><dd class="font-semibold text-slate-900">{{ $lastDuty ? \Carbon\Carbon::parse($lastDuty)->format('d M Y') : '—' }}</dd></div>
                        </dl>
                    </x-shell.card>

                    @if ($departmentBreakdown->isNotEmpty())
                        <x-shell.card>
                            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Department-wise Duties') }}</h3>
                            <div class="space-y-3">
                                @foreach ($departmentBreakdown as $dept)
                                    <div>
                                        <div class="flex justify-between text-sm">
                                            <span class="font-medium text-slate-900">{{ $dept->department_name }}</span>
                                            <span class="text-slate-500">{{ $dept->duties }} {{ __('duties') }}</span>
                                        </div>
                                        <div class="mt-0.5 flex justify-between text-xs text-slate-400">
                                            <span>{{ $dept->present }} {{ __('present') }} &middot; {{ $dept->absent }} {{ __('absent') }}</span>
                                            <span class="tabular-nums">{{ $dept->rate }}%</span>
                                        </div>
                                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                            <div class="kg-progress-fill h-full rounded-full bg-blue-500" style="width: {{ $dept->rate }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </x-shell.card>
                    @endif
                </div>

                <div class="space-y-4 lg:col-span-2">
                    <x-shell.card>
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-slate-700">{{ __('Duty History') }}</h3>
                        </div>

                        <form method="GET" action="{{ route('analytics.profile', $khidmatguzar) }}" class="mb-3 grid grid-cols-2 gap-2 lg:grid-cols-5">
                            <select name="history_department_id" class="rounded-xl border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">{{ __('Any Department') }}</option>
                                @foreach ($historyDepartmentOptions as $id => $name)
                                    <option value="{{ $id }}" @selected((string) $historyDeptId === (string) $id)>{{ $name }}</option>
                                @endforeach
                            </select>
                            <select name="history_status" class="rounded-xl border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">{{ __('Any Status') }}</option>
                                @foreach (['present' => 'Present', 'absent' => 'Absent', 'pending' => 'Pending'] as $val => $label)
                                    <option value="{{ $val }}" @selected($historyStatus === $val)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-text-input name="history_from" type="date" class="block w-full text-xs" :value="$historyFrom" />
                            <x-text-input name="history_to" type="date" class="block w-full text-xs" :value="$historyTo" />
                            <button type="submit" class="kg-tap col-span-2 rounded-xl bg-slate-100 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200 lg:col-span-1">{{ __('Filter') }}</button>
                        </form>

                        @if ($recentHistory->isEmpty())
                            <x-shell.empty-state title="{{ __('No duties match this filter') }}" />
                        @else
                            <div class="space-y-2">
                                @foreach ($recentHistory as $assignment)
                                    <a href="{{ route('analytics.assignment', $assignment) }}" class="kg-tap flex items-center justify-between rounded-xl border border-slate-100 p-3 hover:bg-slate-50">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-slate-900">{{ $assignment->department->name }}</p>
                                            <p class="text-xs text-slate-400">
                                                {{ $assignment->dutySession->date->format('d M Y') }}
                                                @if ($assignment->block_name) &middot; {{ $assignment->block_name }} @endif
                                                @if ($assignment->seat) &middot; {{ __('Seat') }} {{ $assignment->seat }} @endif
                                            </p>
                                        </div>
                                        <x-shell.badge :tone="$assignment->current_status === 'present' ? 'green' : ($assignment->current_status === 'absent' ? 'red' : 'orange')" dot>{{ $assignment->current_status }}</x-shell.badge>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                        <div class="mt-3">{{ $recentHistory->onEachSide(1)->links() }}</div>
                    </x-shell.card>

                    @if ($extraTotal > 0)
                        <x-shell.card>
                            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Extra Present History') }} ({{ $extraTotal }})</h3>
                            <div class="space-y-2">
                                @foreach ($extraHistory as $extra)
                                    <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                                        <div>
                                            <p class="text-sm font-medium text-slate-900">{{ $extra->department_name_snapshot }}</p>
                                            <p class="text-xs text-slate-400">{{ $extra->dutySession->name }} &middot; {{ $extra->marked_at->toIst()->format('d M Y') }} @if($extra->remark) &middot; {{ $extra->remark }} @endif</p>
                                        </div>
                                        <x-shell.badge tone="purple" dot>{{ __('Extra') }}</x-shell.badge>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-3">{{ $extraHistory->onEachSide(1)->links() }}</div>
                        </x-shell.card>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-app-layout>

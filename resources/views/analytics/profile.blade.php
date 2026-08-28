<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Khidmatguzar Profile" :back-url="route('analytics.profile-search')">
            <x-slot:actions>
                <a href="{{ route('reports.khidmatguzar', $khidmatguzar) }}" class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold">{{ __('Report') }}</a>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-4">
        <x-shell.card>
            <div class="flex items-center gap-3">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-blue-50 text-lg font-semibold text-blue-600">
                    {{ mb_substr($khidmatguzar->full_name, 0, 1) }}
                </span>
                <div class="min-w-0">
                    <p class="truncate font-semibold text-slate-900">{{ $khidmatguzar->full_name }}</p>
                    <p class="text-xs text-slate-400">{{ __('ITS') }}: {{ $khidmatguzar->its_id }}</p>
                    @if ($khidmatguzar->jamaat)
                        <p class="text-xs text-slate-400">{{ __('Jamaat') }}: {{ $khidmatguzar->jamaat }}</p>
                    @endif
                </div>
            </div>
        </x-shell.card>

        @if ($total === 0)
            <x-shell.card class="text-center text-slate-400">{{ __('No scheduled duty history available.') }}</x-shell.card>
        @else
            <div class="grid grid-cols-3 gap-3">
                <x-shell.stat-card compact :value="$total" label="Total Duties" tone="blue" />
                <x-shell.stat-card compact :value="$present" label="Present" tone="green" />
                <x-shell.stat-card compact :value="$absent" label="Absent" tone="red" />
            </div>

            <x-shell.card>
                <p class="text-xs font-medium text-slate-400">{{ __('Attendance Rate') }}</p>
                <p class="text-3xl font-bold text-emerald-600">{{ $rate }}%</p>
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
                                    <span>{{ $dept->rate }}%</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif

            <x-shell.card>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-700">{{ __('Duty History') }}</h3>
                </div>

                <form method="GET" action="{{ route('analytics.profile', $khidmatguzar) }}" class="mb-3 grid grid-cols-2 gap-2">
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
                    <button type="submit" class="col-span-2 rounded-xl bg-slate-100 py-2 text-xs font-semibold text-slate-700">{{ __('Filter History') }}</button>
                </form>

                <div class="space-y-2">
                    @foreach ($recentHistory as $assignment)
                        <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $assignment->department->name }}</p>
                                <p class="text-xs text-slate-400">
                                    {{ $assignment->dutySession->date->format('d M Y') }}
                                    @if ($assignment->block_name) &middot; {{ $assignment->block_name }} @endif
                                    @if ($assignment->seat) &middot; {{ __('Seat') }} {{ $assignment->seat }} @endif
                                </p>
                            </div>
                            <x-shell.badge :tone="$assignment->current_status === 'present' ? 'green' : ($assignment->current_status === 'absent' ? 'red' : 'orange')">{{ $assignment->current_status }}</x-shell.badge>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3">{{ $recentHistory->onEachSide(1)->links() }}</div>
            </x-shell.card>
        @endif

        @if ($extraTotal > 0)
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Extra Present History') }} ({{ $extraTotal }})</h3>
                <div class="space-y-2">
                    @foreach ($extraHistory as $extra)
                        <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $extra->department_name_snapshot }}</p>
                                <p class="text-xs text-slate-400">{{ $extra->dutySession->name }} &middot; {{ $extra->marked_at->format('d M Y') }} @if($extra->remark) &middot; {{ $extra->remark }} @endif</p>
                            </div>
                            <x-shell.badge tone="purple">{{ __('Extra') }}</x-shell.badge>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3">{{ $extraHistory->onEachSide(1)->links() }}</div>
            </x-shell.card>
        @endif
    </div>
</x-app-layout>

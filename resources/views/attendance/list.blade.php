<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Attendance List" :subtitle="'Session: '.$dutySession->date->format('d M Y')" :back-url="route('sessions.show', $dutySession)">
            <div class="mt-1 flex gap-1 overflow-x-auto rounded-xl bg-white/10 p-1">
                @foreach (['all' => 'All', 'present' => 'Present', 'pending' => 'Pending', 'extra' => 'Extra'] as $key => $label)
                    <a href="{{ route('attendance.shell.list', [$dutySession, 'tab' => $key]) }}"
                       class="flex-1 rounded-lg px-2 py-1.5 text-center text-xs font-semibold whitespace-nowrap {{ $tab === $key ? 'bg-white text-navy-900' : 'text-white/70' }}">
                        {{ $label }} ({{ $counts[$key] }})
                    </a>
                @endforeach
            </div>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-4">
        <x-shell.card>
            <form method="GET" action="{{ route('attendance.shell.list', $dutySession) }}" class="flex gap-2">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <x-text-input name="q" type="text" class="block w-full" :value="$search" placeholder="{{ __('Search by name or ITS number') }}" />
                @if ($departments->isNotEmpty())
                    <select name="department_id" class="rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" onchange="this.form.submit()">
                        <option value="">{{ __('All Depts') }}</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->id }}" @selected((string) $departmentId === (string) $dept->id)>{{ $dept->name }}</option>
                        @endforeach
                    </select>
                @endif
                <x-shell.button tone="primary" type="submit" class="w-auto px-4">{{ __('Go') }}</x-shell.button>
            </form>
        </x-shell.card>

        @if ($tab === 'extra')
            @if ($extraPresents->isEmpty())
                <x-shell.card class="text-center text-slate-400">{{ __('No Extra Present records yet.') }}</x-shell.card>
            @else
                @foreach ($extraPresents as $extra)
                    <div class="flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-violet-50 text-sm font-semibold text-violet-500">
                                {{ mb_substr($extra->full_name_snapshot, 0, 1) }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $extra->full_name_snapshot }}</p>
                                <p class="truncate text-xs text-slate-400">{{ __('ITS') }}: {{ $extra->its_id_snapshot }} &middot; {{ $extra->department_name_snapshot }} &middot; {{ $extra->marked_at->format('H:i') }}</p>
                            </div>
                        </div>
                        <x-shell.badge tone="purple">{{ __('Extra') }}</x-shell.badge>
                    </div>
                @endforeach
            @endif
        @elseif ($assignments->isEmpty())
            <x-shell.card class="text-center text-slate-400">{{ __('No duty assignments found.') }}</x-shell.card>
        @else
            @foreach ($assignments as $assignment)
                <div class="flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-500">
                            {{ mb_substr($assignment->full_name_snapshot, 0, 1) }}
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $assignment->full_name_snapshot }}</p>
                            <p class="truncate text-xs text-slate-400">{{ __('ITS') }}: {{ $assignment->khidmatguzar->its_id }} &middot; {{ $assignment->department->name }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <x-shell.badge :tone="$assignment->current_status === 'present' ? 'green' : ($assignment->current_status === 'absent' ? 'red' : 'orange')">{{ $assignment->current_status }}</x-shell.badge>
                        @if ($assignment->attendance_marked_at)
                            <p class="mt-1 text-[10px] text-slate-400">{{ $assignment->attendance_marked_at->format('H:i') }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</x-app-layout>

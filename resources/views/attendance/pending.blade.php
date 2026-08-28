<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="End of Day Review" :subtitle="$dutySession->name.' · '.$dutySession->date->format('d M Y')" :back-url="route('sessions.show', $dutySession)">
            <x-slot:actions>
                <x-shell.badge :tone="$dutySession->statusTone()">{{ $dutySession->status }}</x-shell.badge>
            </x-slot:actions>

            <div class="grid grid-cols-5 gap-2 text-center">
                <div class="rounded-xl bg-white/10 px-1 py-2">
                    <p class="text-lg font-semibold">{{ $counts['scheduled'] }}</p>
                    <p class="text-[10px] text-white/60">{{ __('Scheduled') }}</p>
                </div>
                <div class="rounded-xl bg-white/10 px-1 py-2">
                    <p class="text-lg font-semibold text-emerald-300">{{ $counts['present'] }}</p>
                    <p class="text-[10px] text-white/60">{{ __('Present') }}</p>
                </div>
                <div class="rounded-xl bg-white/10 px-1 py-2">
                    <p class="text-lg font-semibold text-red-300">{{ $counts['absent'] }}</p>
                    <p class="text-[10px] text-white/60">{{ __('Absent') }}</p>
                </div>
                <div class="rounded-xl bg-white/10 px-1 py-2">
                    <p class="text-lg font-semibold text-orange-300">{{ $counts['pending'] }}</p>
                    <p class="text-[10px] text-white/60">{{ __('Pending') }}</p>
                </div>
                <div class="rounded-xl bg-white/10 px-1 py-2">
                    <p class="text-lg font-semibold text-violet-300">{{ $counts['extra'] }}</p>
                    <p class="text-[10px] text-white/60">{{ __('Extra') }}</p>
                </div>
            </div>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-4">
        @foreach (['flash_success' => 'green', 'flash_info' => 'blue', 'flash_warning' => 'orange', 'flash_error' => 'red'] as $key => $tone)
            @if (session($key))
                @php $bg = ['green' => 'bg-emerald-50 text-emerald-700', 'blue' => 'bg-blue-50 text-blue-700', 'orange' => 'bg-orange-50 text-orange-700', 'red' => 'bg-red-50 text-red-700'][$tone]; @endphp
                <div class="rounded-2xl {{ $bg }} p-4 text-sm">{{ session($key) }}</div>
            @endif
        @endforeach

        <x-shell.card>
            <form method="GET" action="{{ route('attendance.shell.pending', $dutySession) }}">
                <x-text-input name="q" type="text" class="block w-full" :value="$search" placeholder="{{ __('Search pending persons') }}" />
            </form>
        </x-shell.card>

        @if ($pendingAssignments->isEmpty())
            <x-shell.card class="text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-500">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                </span>
                <p class="mt-3 font-semibold text-slate-900">{{ __('No pending assignments.') }}</p>
                <p class="mt-1 text-sm text-slate-400">{{ __('Everyone scheduled has been marked Present or Absent.') }}</p>
                @if ($dutySession->isActive())
                    <div class="mt-4">
                        <x-shell.button tone="primary" href="{{ route('sessions.close-summary', $dutySession) }}">{{ __('Review & Close Session') }}</x-shell.button>
                    </div>
                @endif
            </x-shell.card>
        @else
            <x-shell.info-card>
                {{ __(':count person(s) are still pending. Please mark them as Present or Absent.', ['count' => $pendingAssignments->count()]) }}
            </x-shell.info-card>

            <div class="space-y-2">
                @foreach ($pendingAssignments as $assignment)
                    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $assignment->full_name_snapshot }}</p>
                                <p class="truncate text-xs text-slate-400">{{ __('ITS') }}: {{ $assignment->khidmatguzar->its_id }} &middot; {{ $assignment->department->name }}</p>
                            </div>
                            <x-shell.badge tone="orange">{{ __('Pending') }}</x-shell.badge>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <form method="POST" action="{{ route('attendance.present', $dutySession) }}">
                                @csrf
                                <input type="hidden" name="assignment_ids[]" value="{{ $assignment->id }}">
                                <input type="hidden" name="return_to" value="pending">
                                <button type="submit" class="w-full rounded-xl bg-emerald-600 py-2 text-xs font-semibold text-white hover:bg-emerald-700">{{ __('Present') }}</button>
                            </form>
                            <form method="POST" action="{{ route('attendance.absent', $dutySession) }}" onsubmit="return confirm('{{ __('Mark this person Absent?') }}')">
                                @csrf
                                <input type="hidden" name="assignment_id" value="{{ $assignment->id }}">
                                <input type="hidden" name="return_to" value="pending">
                                <button type="submit" class="w-full rounded-xl border border-red-200 py-2 text-xs font-semibold text-red-600">{{ __('Absent') }}</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($dutySession->isActive() && auth()->user()->canManageSessions())
                <div x-data="{ confirming: false }">
                    <x-shell.button tone="warning" type="button" @click="confirming = true" x-show="! confirming">
                        {{ __('Mark All Remaining as Absent') }}
                    </x-shell.button>

                    <x-shell.card x-show="confirming" x-cloak class="border-red-100 bg-red-50/40">
                        <p class="text-sm font-semibold text-red-700">
                            {{ __(':count pending assignment(s) will be marked Absent.', ['count' => $pendingAssignments->count()]) }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('This cannot be undone individually — confirm to proceed.') }}</p>
                        <form method="POST" action="{{ route('attendance.absent-all', $dutySession) }}" class="mt-3 grid grid-cols-2 gap-3">
                            @csrf
                            <x-shell.button tone="warning" type="submit">{{ __('Confirm') }}</x-shell.button>
                            <button type="button" @click="confirming = false" class="w-full rounded-xl border border-slate-300 py-3 text-sm font-semibold text-slate-600">{{ __('Cancel') }}</button>
                        </form>
                    </x-shell.card>
                </div>
            @endif
        @endif
    </div>
</x-app-layout>

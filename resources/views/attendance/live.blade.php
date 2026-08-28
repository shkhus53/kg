<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Live Attendance" :subtitle="'Session: '.$dutySession->date->format('d M Y')" :back-url="route('sessions.show', $dutySession)">
            <x-slot:actions>
                <x-shell.badge :tone="$dutySession->statusTone()">{{ $dutySession->status }}</x-shell.badge>
            </x-slot:actions>

            <div class="grid grid-cols-4 gap-2 text-center">
                <div class="rounded-xl bg-white/10 px-1 py-2">
                    <p class="text-lg font-semibold">{{ $counts['scheduled'] }}</p>
                    <p class="text-[10px] text-white/60">{{ __('Scheduled') }}</p>
                </div>
                <div class="rounded-xl bg-white/10 px-1 py-2">
                    <p class="text-lg font-semibold text-emerald-300">{{ $counts['present'] }}</p>
                    <p class="text-[10px] text-white/60">{{ __('Present') }}</p>
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

            @php $pct = $counts['scheduled'] > 0 ? round(100 * $counts['present'] / $counts['scheduled']) : 0; @endphp
            <div class="mt-3">
                <div class="h-1.5 rounded-full bg-white/10">
                    <div class="h-1.5 rounded-full bg-emerald-400" style="width: {{ $pct }}%"></div>
                </div>
                <p class="mt-1 text-right text-[11px] text-white/60">{{ $counts['present'] }} / {{ $counts['scheduled'] }} ({{ $pct }}%)</p>
            </div>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">

        @foreach (['flash_success' => 'green', 'flash_info' => 'blue', 'flash_warning' => 'orange', 'flash_error' => 'red'] as $key => $tone)
            @if (session($key))
                @php
                    $bg = ['green' => 'bg-emerald-50 text-emerald-700', 'blue' => 'bg-blue-50 text-blue-700', 'orange' => 'bg-orange-50 text-orange-700', 'red' => 'bg-red-50 text-red-700'][$tone];
                @endphp
                <div class="rounded-2xl {{ $bg }} p-4 text-sm">{{ session($key) }}</div>
            @endif
        @endforeach

        @unless ($dutySession->isActive())
            <div class="rounded-2xl bg-slate-100 p-4 text-sm text-slate-500">
                {{ __('This session is :status. Attendance marking is disabled.', ['status' => $dutySession->status]) }}
            </div>
        @endunless

        <x-shell.card>
            <form method="GET" action="{{ route('attendance.shell.live', $dutySession) }}" class="space-y-3">
                <div>
                    <x-input-label for="its" :value="__('Enter ITS Number')" />
                    <x-text-input id="its" name="its" type="text" inputmode="numeric" maxlength="20" class="mt-1 block w-full text-center text-lg tracking-widest" :value="$itsId" placeholder="8 digit ITS number" />
                </div>
                <x-shell.button tone="primary" type="submit">{{ __('Search') }}</x-shell.button>
            </form>

            <div class="my-4 flex items-center gap-3 text-xs text-slate-400">
                <div class="h-px flex-1 bg-slate-100"></div>{{ __('or') }}<div class="h-px flex-1 bg-slate-100"></div>
            </div>

            <form method="GET" action="{{ route('attendance.shell.live', $dutySession) }}" class="space-y-3">
                <x-text-input name="name" type="text" class="block w-full" :value="$nameQuery" placeholder="{{ __('Search by Name') }}" />
                <x-shell.button tone="outline" type="submit">{{ __('Search by Name') }}</x-shell.button>
            </form>
        </x-shell.card>

        {{-- Name search results --}}
        @if (! is_null($nameMatches))
            <x-shell.card>
                <p class="mb-3 text-sm font-semibold text-slate-700">{{ __('Matches for ":q"', ['q' => $nameQuery]) }}</p>
                @if ($nameMatches->isEmpty())
                    <p class="text-sm text-slate-400">{{ __('No Khidmatguzar found with that name.') }}</p>
                @else
                    <div class="space-y-2">
                        @foreach ($nameMatches as $person)
                            <a href="{{ route('attendance.shell.live', [$dutySession, 'its' => $person->its_id]) }}" class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                                <div>
                                    <p class="text-sm font-medium text-slate-900">{{ $person->full_name }}</p>
                                    <p class="text-xs text-slate-400">
                                        {{ __('ITS') }}: {{ $person->its_id }}
                                        @if ($person->dutyAssignments->isNotEmpty())
                                            &middot; {{ $person->dutyAssignments->pluck('department.name')->unique()->implode(', ') }}
                                        @else
                                            &middot; {{ __('not scheduled this session') }}
                                        @endif
                                    </p>
                                </div>
                                <svg class="h-4 w-4 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-shell.card>
        @endif

        @if ($itsId !== '')
            @if ($matches->count() === 1)
                @php $assignment = $matches->first(); @endphp
                <x-shell.card x-data="{ remarkOpen: false }">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $assignment->khidmatguzar->full_name }}</p>
                            <p class="text-xs text-slate-400">{{ __('ITS') }}: {{ $assignment->khidmatguzar->its_id }}</p>
                        </div>
                        <x-shell.badge :tone="$assignment->current_status === 'present' ? 'green' : ($assignment->current_status === 'absent' ? 'red' : 'orange')">{{ $assignment->current_status }}</x-shell.badge>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-400">{{ __('Jamaat') }}</dt><dd class="text-slate-900">{{ $assignment->jamaat_snapshot ?: '—' }}</dd></div>
                        <div><dt class="text-slate-400">{{ __('Department') }}</dt><dd class="text-slate-900">{{ $assignment->department->name }}</dd></div>
                        <div><dt class="text-slate-400">{{ __('Block') }}</dt><dd class="text-slate-900">{{ $assignment->block_name ?: '—' }}</dd></div>
                        <div><dt class="text-slate-400">{{ __('Seat') }}</dt><dd class="text-slate-900">{{ $assignment->seat ?: '—' }}</dd></div>
                        <div><dt class="text-slate-400">{{ __('Day') }}</dt><dd class="text-slate-900">{{ $assignment->day_alias ?: $assignment->day ?: '—' }}</dd></div>
                    </dl>

                    @if ($assignment->current_status === 'pending')
                        <div class="mt-4 rounded-xl bg-orange-50 p-3 text-xs text-orange-700">
                            {{ __('This person is in this session\'s duty list and not yet marked.') }}
                        </div>

                        <form method="POST" action="{{ route('attendance.present', $dutySession) }}" class="mt-4 space-y-3">
                            @csrf
                            <input type="hidden" name="assignment_ids[]" value="{{ $assignment->id }}">
                            <input type="hidden" name="its" value="{{ $itsId }}">

                            <button type="button" @click="remarkOpen = !remarkOpen" class="text-xs font-semibold text-blue-600">
                                {{ __('+ Add Remark') }}
                            </button>
                            <div x-show="remarkOpen" x-cloak>
                                <textarea name="remark" rows="2" maxlength="500" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('Optional remark') }}"></textarea>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <x-shell.button tone="success" type="submit" :disabled="! $dutySession->isActive()">{{ __('Mark Present') }}</x-shell.button>
                                <x-shell.button tone="outline" href="{{ route('attendance.shell.live', $dutySession) }}">{{ __('Cancel') }}</x-shell.button>
                            </div>
                        </form>

                        @if ($dutySession->isActive())
                            <form method="POST" action="{{ route('attendance.absent', $dutySession) }}" class="mt-2" onsubmit="return confirm('{{ __('Mark this person Absent?') }}')">
                                @csrf
                                <input type="hidden" name="assignment_id" value="{{ $assignment->id }}">
                                <input type="hidden" name="its" value="{{ $itsId }}">
                                <button type="submit" class="w-full rounded-xl border border-red-200 py-2 text-xs font-semibold text-red-600">{{ __('Mark Absent') }}</button>
                            </form>
                        @endif
                    @elseif ($assignment->current_status === 'present')
                        <div class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">
                            <p class="font-semibold">{{ __('Already Present') }}</p>
                            <p class="text-xs">
                                {{ $assignment->attendance_marked_at?->format('d M Y H:i') }}
                                @if ($assignment->attendanceMarkedBy) &middot; {{ $assignment->attendanceMarkedBy->name }} @endif
                            </p>
                        </div>
                    @else
                        <div class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">
                            <p class="font-semibold">{{ __('Already marked Absent') }}</p>
                            <p class="text-xs">
                                {{ $assignment->attendance_marked_at?->format('d M Y H:i') }}
                                @if ($assignment->attendanceMarkedBy) &middot; {{ $assignment->attendanceMarkedBy->name }} @endif
                            </p>
                        </div>

                        @if ($dutySession->isActive())
                            <form method="POST" action="{{ route('attendance.present', $dutySession) }}" class="mt-3" x-data="{ submitting: false }" @submit="submitting = true">
                                @csrf
                                <input type="hidden" name="assignment_ids[]" value="{{ $assignment->id }}">
                                <input type="hidden" name="its" value="{{ $itsId }}">
                                <p class="mb-2 text-xs text-slate-500">{{ __('Person arrived late? Correct this to Present.') }}</p>
                                <x-shell.button tone="success" type="submit" x-bind:disabled="submitting">{{ __('Mark Present') }}</x-shell.button>
                            </form>
                        @endif
                    @endif
                </x-shell.card>
            @elseif ($matches->count() > 1)
                <x-shell.card x-data="{ selected: [], sessionActive: {{ $dutySession->isActive() ? 'true' : 'false' }} }">
                    <p class="mb-1 text-sm font-semibold text-slate-700">{{ __('Multiple Assignments Found') }}</p>
                    <p class="mb-4 text-xs text-slate-400">
                        {{ __('ITS :its has :count separate duty assignments in this session. Select which one(s) to mark.', ['its' => $itsId, 'count' => $matches->count()]) }}
                    </p>

                    <form method="POST" action="{{ route('attendance.present', $dutySession) }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="its" value="{{ $itsId }}">

                        <div class="space-y-2">
                            @foreach ($matches as $assignment)
                                @php $correctable = in_array($assignment->current_status, ['pending', 'absent'], true); @endphp
                                <label class="flex items-center justify-between rounded-xl border border-slate-100 p-3 {{ ! $correctable ? 'opacity-60' : '' }}">
                                    <span class="flex items-center gap-3">
                                        <input type="checkbox" name="assignment_ids[]" value="{{ $assignment->id }}"
                                               x-model="selected"
                                               @unless ($correctable) disabled @endunless
                                               class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                        <span>
                                            <span class="block text-sm font-medium text-slate-900">{{ $assignment->department->name }}</span>
                                            <span class="block text-xs text-slate-400">{{ $assignment->block_name }} &middot; {{ __('Seat') }} {{ $assignment->seat ?: '—' }}</span>
                                        </span>
                                    </span>
                                    <x-shell.badge :tone="$assignment->current_status === 'present' ? 'green' : ($assignment->current_status === 'absent' ? 'red' : 'orange')">{{ $assignment->current_status }}</x-shell.badge>
                                </label>
                            @endforeach
                        </div>

                        <x-shell.button tone="success" type="submit" x-bind:disabled="selected.length === 0 || !sessionActive">
                            {{ __('Mark Selected Present') }}
                        </x-shell.button>
                    </form>
                </x-shell.card>
            @elseif ($alreadyExtra)
                <x-shell.card>
                    <p class="font-semibold text-violet-700">{{ __('Already marked Extra Present') }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $knownPerson->full_name }} &middot; {{ $alreadyExtra->department_name_snapshot }}
                        &middot; {{ $alreadyExtra->marked_at->format('d M Y H:i') }}
                    </p>
                </x-shell.card>
            @else
                <x-shell.card class="border-orange-100 bg-orange-50/40">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-orange-700">{{ __('Not in Today\'s List') }}</p>
                            <p class="text-xs text-slate-500">{{ __('ITS Number') }}: {{ $itsId }}</p>
                        </div>
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-orange-100 text-orange-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /></svg>
                        </span>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">
                        {{ __('This ITS Number is not on this session\'s duty list.') }}
                        @if ($knownPerson)
                            {{ __('Known person: :name.', ['name' => $knownPerson->full_name]) }}
                        @endif
                    </p>

                    <form method="POST" action="{{ route('attendance.extra-present', $dutySession) }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="its" value="{{ $itsId }}">

                        @unless ($knownPerson)
                            <div>
                                <x-input-label for="full_name" :value="__('Full Name')" />
                                <x-text-input id="full_name" name="full_name" type="text" class="mt-1 block w-full" required />
                            </div>
                        @endunless

                        <div>
                            <x-input-label :value="__('Select Department')" />
                            <select name="department_id" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">{{ __('Choose department…') }}</option>
                                @foreach ($departments as $dept)
                                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-[11px] text-slate-400">{{ __('Populated from this session\'s current departments.') }}</p>
                        </div>

                        <div>
                            <x-input-label for="remark" :value="__('Remarks (optional)')" />
                            <textarea id="remark" name="remark" rows="2" maxlength="500" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                        </div>

                        <x-shell.button tone="warning" type="submit" :disabled="! $dutySession->isActive() || $departments->isEmpty()">
                            {{ __('Mark Extra Present') }}
                        </x-shell.button>
                        @if ($departments->isEmpty())
                            <p class="text-center text-xs text-red-500">{{ __('This session has no imported departments yet.') }}</p>
                        @endif
                    </form>
                </x-shell.card>
            @endif
        @endif
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Live Attendance" :subtitle="'Session: '.$dutySession->date->format('d M Y')" :back-url="route('sessions.show', $dutySession)">
            <x-slot:actions>
                <div class="flex flex-col items-end gap-1">
                    <x-shell.badge :tone="$dutySession->statusTone()">{{ $dutySession->status }}</x-shell.badge>
                    <x-shell.connectivity-badge />
                </div>
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

        {{-- Phase 2: hidden until JS confirms a non-zero offline queue for this session. --}}
        <div id="offline-queue-banner" hidden class="rounded-2xl bg-orange-50 p-4 text-sm text-orange-700">
            <span data-queue-text></span>
            <button type="button" id="offline-sync-now" class="ml-2 font-semibold underline">{{ __('Sync now') }}</button>
        </div>

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
                <x-shell.card class="kg-enter" x-data="{ remarkOpen: false }">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $assignment->khidmatguzar->full_name }}</p>
                            <p class="text-xs text-slate-400">{{ __('ITS') }}: {{ $assignment->khidmatguzar->its_id }}</p>
                        </div>
                        <x-shell.badge :tone="$assignment->current_status === 'present' ? 'green' : ($assignment->current_status === 'absent' ? 'red' : 'orange')" dot>{{ $assignment->current_status }}</x-shell.badge>
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

                        @can('mark_attendance')
                            <form method="POST" action="{{ route('attendance.present', $dutySession) }}" class="mt-4 space-y-3 js-attendance-form" data-offline-action="present" data-assignment-id="{{ $assignment->id }}">
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
                        @endcan

                        @if ($dutySession->isActive())
                            @can('mark_attendance')
                                <form method="POST" action="{{ route('attendance.absent', $dutySession) }}" class="mt-2 js-attendance-form" data-offline-action="absent" data-assignment-id="{{ $assignment->id }}" onsubmit="return confirm('{{ __('Mark this person Absent?') }}')">
                                    @csrf
                                    <input type="hidden" name="assignment_id" value="{{ $assignment->id }}">
                                    <input type="hidden" name="its" value="{{ $itsId }}">
                                    <button type="submit" class="w-full rounded-xl border border-red-200 py-2 text-xs font-semibold text-red-600">{{ __('Mark Absent') }}</button>
                                </form>
                            @endcan
                        @endif
                    @elseif ($assignment->current_status === 'present')
                        <div class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">
                            <p class="font-semibold">{{ __('Already Present') }}</p>
                            <p class="text-xs">
                                {{ $assignment->attendance_marked_at?->toIst()->format('d M Y H:i') }}
                                @if ($assignment->attendanceMarkedBy) &middot; {{ $assignment->attendanceMarkedBy->name }} @endif
                            </p>
                        </div>
                    @else
                        <div class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">
                            <p class="font-semibold">{{ __('Already marked Absent') }}</p>
                            <p class="text-xs">
                                {{ $assignment->attendance_marked_at?->toIst()->format('d M Y H:i') }}
                                @if ($assignment->attendanceMarkedBy) &middot; {{ $assignment->attendanceMarkedBy->name }} @endif
                            </p>
                        </div>

                        @if ($dutySession->isActive())
                            @can('correct_attendance')
                                <form method="POST" action="{{ route('attendance.present', $dutySession) }}" class="mt-3 js-attendance-form" data-offline-action="present" data-assignment-id="{{ $assignment->id }}" x-data="{ submitting: false }" @submit="submitting = true">
                                    @csrf
                                    <input type="hidden" name="assignment_ids[]" value="{{ $assignment->id }}">
                                    <input type="hidden" name="its" value="{{ $itsId }}">
                                    <p class="mb-2 text-xs text-slate-500">{{ __('Person arrived late? Correct this to Present.') }}</p>
                                    <x-shell.button tone="success" type="submit" x-bind:disabled="submitting">{{ __('Mark Present') }}</x-shell.button>
                                </form>
                            @endcan
                        @endif
                    @endif
                </x-shell.card>
            @elseif ($matches->count() > 1)
                <x-shell.card x-data="{ selected: [], sessionActive: {{ $dutySession->isActive() ? 'true' : 'false' }} }">
                    <p class="mb-1 text-sm font-semibold text-slate-700">{{ __('Multiple Assignments Found') }}</p>
                    <p class="mb-4 text-xs text-slate-400">
                        {{ __('ITS :its has :count separate duty assignments in this session. Select which one(s) to mark.', ['its' => $itsId, 'count' => $matches->count()]) }}
                    </p>

                    @canany(['mark_attendance', 'correct_attendance'])
                        <form method="POST" action="{{ route('attendance.present', $dutySession) }}" class="space-y-3" onsubmit="return confirm('{{ __('Mark the selected assignment(s) Present?') }}')">
                            @csrf
                            <input type="hidden" name="its" value="{{ $itsId }}">

                            <div class="space-y-2">
                                @foreach ($matches as $assignment)
                                    @php
                                        $correctable = ($assignment->current_status === 'pending' && auth()->user()->hasPermission('mark_attendance'))
                                            || ($assignment->current_status === 'absent' && auth()->user()->hasPermission('correct_attendance'));
                                    @endphp
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
                    @else
                        <div class="space-y-2">
                            @foreach ($matches as $assignment)
                                <div class="flex items-center justify-between rounded-xl border border-slate-100 p-3">
                                    <span>
                                        <span class="block text-sm font-medium text-slate-900">{{ $assignment->department->name }}</span>
                                        <span class="block text-xs text-slate-400">{{ $assignment->block_name }} &middot; {{ __('Seat') }} {{ $assignment->seat ?: '—' }}</span>
                                    </span>
                                    <x-shell.badge :tone="$assignment->current_status === 'present' ? 'green' : ($assignment->current_status === 'absent' ? 'red' : 'orange')">{{ $assignment->current_status }}</x-shell.badge>
                                </div>
                            @endforeach
                        </div>
                    @endcanany
                </x-shell.card>
            @elseif ($alreadyExtra)
                <x-shell.card>
                    <p class="font-semibold text-violet-700">{{ __('Already marked Extra Present') }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $knownPerson->full_name }} &middot; {{ $alreadyExtra->department_name_snapshot }}
                        &middot; {{ $alreadyExtra->marked_at->toIst()->format('d M Y H:i') }}
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
                    <p class="mt-1 text-[11px] text-slate-400">
                        {{ __('Submitting this form works offline. Starting a new ITS search needs a connection.') }}
                    </p>

                    @can('mark_extra_present')
                        <form method="POST" action="{{ route('attendance.extra-present', $dutySession) }}" class="mt-4 space-y-3 js-extra-present-form">
                            @csrf
                            <input type="hidden" name="its" value="{{ $itsId }}">

                            @unless ($knownPerson)
                                <div>
                                    <x-input-label for="full_name" :value="__('Full Name')" />
                                    <x-text-input id="full_name" name="full_name" type="text" class="mt-1 block w-full" required />
                                </div>
                            @endunless

                            @php
                                $knownGender = $knownPerson ? \App\Support\Gender::shortLabel($knownPerson->gender) : null;
                                $knownGender = $knownGender === 'M' ? 'Male' : ($knownGender === 'F' ? 'Female' : null);
                            @endphp
                            <div>
                                <x-input-label :value="__('Gender')" />
                                <select name="gender" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ __('Choose gender…') }}</option>
                                    <option value="Male" @selected($knownGender === 'Male')>{{ __('Male') }}</option>
                                    <option value="Female" @selected($knownGender === 'Female')>{{ __('Female') }}</option>
                                </select>
                            </div>

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
                    @endcan
                </x-shell.card>
            @endif
        @endif
    </div>

    @if ($dutySession->isActive())
        <script>
        (function () {
            if (!window.KGOffline) return; // offline.js failed to load — every form still works as a normal POST, no behavior change.

            var sessionId = {{ $dutySession->id }};
            var userId = {{ auth()->id() }};
            var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            var offline = new window.KGOffline.OfflineAttendance({ sessionId: sessionId, userId: userId, csrfToken: csrfToken });
            window.kgOffline = offline;

            offline.provision().catch(function () {}); // best-effort; if we're already offline on load there's nothing to provision yet
            offline.startAutoSync();

            var badge = document.getElementById('connectivity-badge');
            var banner = document.getElementById('offline-queue-banner');

            // Every state pairs a distinct background tone + dot color + icon
            // + label — never color alone, per the offline-UX requirement
            // that connectivity/sync condition be unmistakable at a glance.
            var BADGE_STATES = {
                online: { bg: 'bg-white/10', dot: 'bg-emerald-400', pulse: false, icon: '<path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/>' },
                syncing: { bg: 'bg-orange-400/20', dot: 'bg-orange-300', pulse: true, icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>' },
                conflict: { bg: 'bg-red-400/25', dot: 'bg-red-400', pulse: true, icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>' },
                offline: { bg: 'bg-slate-400/20', dot: 'bg-slate-300', pulse: false, icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 010 12.728m0 0l-3.536-3.536m3.536 3.536L21 21M15.536 8.464a5 5 0 010 7.072m-7.072 0a5 5 0 010-7.072m-2.828 9.9a9 9 0 010-12.728M3 3l18 18"/>' },
            };

            function paintBadge(state, label) {
                var cfg = BADGE_STATES[state] || BADGE_STATES.online;
                badge.dataset.state = state;
                badge.className = 'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-semibold transition-colors duration-200 ' + cfg.bg;
                badge.querySelector('[data-label]').textContent = label;
                var dot = badge.querySelector('[data-dot]');
                dot.className = 'h-1.5 w-1.5 rounded-full ' + cfg.dot + (cfg.pulse ? ' kg-pulse-dot' : '');
                var iconEl = badge.querySelector('[data-icon]');
                if (iconEl) { iconEl.innerHTML = cfg.icon; }
            }

            function refreshUi() {
                offline.queueSummary().then(function (s) {
                    var pending = s.queued + s.syncing;
                    var issues = s.conflict + s.rejected + s.operator_mismatch + s.blocked;

                    if (issues > 0) {
                        paintBadge('conflict', issues + ' need attention');
                    } else if (pending > 0) {
                        paintBadge('syncing', pending + ' pending sync');
                    } else if (navigator.onLine) {
                        paintBadge('online', 'Online');
                    } else {
                        paintBadge('offline', 'Offline');
                    }

                    if (pending + issues > 0) {
                        banner.hidden = false;
                        banner.querySelector('[data-queue-text]').textContent =
                            pending + ' attendance action(s) waiting to sync' + (issues ? ', ' + issues + ' need review' : '') + '.';
                    } else {
                        banner.hidden = true;
                    }
                });
            }

            offline.onChange(refreshUi);
            refreshUi();
            setInterval(refreshUi, 5000);
            window.addEventListener('online', refreshUi);
            window.addEventListener('offline', refreshUi);

            document.getElementById('offline-sync-now').addEventListener('click', function () {
                offline.sync({ manual: true }).then(refreshUi);
            });

            // Single-assignment Present/Absent/Correction forms: try a real
            // network request first (this is how "Wi-Fi but no internet" is
            // actually detected, not navigator.onLine alone); only fall back
            // to the offline queue if that request genuinely fails.
            document.querySelectorAll('.js-attendance-form').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var action = form.dataset.offlineAction;
                    var assignmentId = parseInt(form.dataset.assignmentId, 10);
                    var remarkField = form.querySelector('[name="remark"]');
                    var remark = remarkField ? remarkField.value : null;

                    var ctrl = new AbortController();
                    var timeout = setTimeout(function () { ctrl.abort(); }, 4000);

                    fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        redirect: 'follow',
                        signal: ctrl.signal,
                        headers: { 'X-CSRF-TOKEN': csrfToken },
                        body: new FormData(form),
                    }).then(function (res) {
                        clearTimeout(timeout);
                        if (res.ok || res.redirected) {
                            window.location.href = res.url || window.location.href;
                        } else {
                            return Promise.reject(new Error('http_' + res.status));
                        }
                    }).catch(function () {
                        clearTimeout(timeout);
                        offline.markAttendance(assignmentId, action, remark).then(function () {
                            queueOfflineFeedback(form);
                            refreshUi();
                        });
                    });
                });
            });

            function queueOfflineFeedback(form) {
                var note = document.createElement('p');
                note.className = 'mt-2 text-xs font-semibold text-orange-600';
                note.textContent = '{{ __('Saved offline — will sync automatically when connection returns.') }}';
                form.after(note);
                form.querySelectorAll('button, input, select, textarea').forEach(function (el) { el.disabled = true; });

                var card = form.closest('.kg-card-hover, [class*="rounded-2xl"]');
                if (card) { card.classList.add('kg-flash-success'); }
            }

            // Extra Present: can be queued offline once this form has already
            // been reached while online (search itself still requires
            // connectivity — starting a brand-new Extra Present lookup from a
            // fully offline cold start is not supported in this phase, since
            // it needs a client-rendered search UI that doesn't exist yet).
            document.querySelectorAll('.js-extra-present-form').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();

                    var ctrl = new AbortController();
                    var timeout = setTimeout(function () { ctrl.abort(); }, 4000);

                    fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        redirect: 'follow',
                        signal: ctrl.signal,
                        headers: { 'X-CSRF-TOKEN': csrfToken },
                        body: new FormData(form),
                    }).then(function (res) {
                        clearTimeout(timeout);
                        if (res.ok || res.redirected) {
                            window.location.href = res.url || window.location.href;
                        } else {
                            return Promise.reject(new Error('http_' + res.status));
                        }
                    }).catch(function () {
                        clearTimeout(timeout);
                        var data = new FormData(form);
                        offline.markExtraPresent({
                            its: data.get('its'),
                            fullName: data.get('full_name'),
                            gender: data.get('gender'),
                            departmentId: data.get('department_id') ? parseInt(data.get('department_id'), 10) : null,
                            remark: data.get('remark'),
                        }).then(function () {
                            queueOfflineFeedback(form);
                            refreshUi();
                        });
                    });
                });
            });
        })();
        </script>
    @endif
</x-app-layout>

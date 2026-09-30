<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="End of Day Review" :subtitle="$dutySession->name.' · '.$dutySession->date->format('d M Y')" :back-url="route('sessions.show', $dutySession)">
            <x-slot:actions>
                <div class="flex flex-col items-end gap-1">
                    <x-shell.badge :tone="$dutySession->statusTone()">{{ $dutySession->status }}</x-shell.badge>
                    <x-shell.connectivity-badge />
                </div>
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

            <div class="mt-3 flex items-center justify-between rounded-xl bg-white/10 px-3 py-2 text-[11px] text-white/70">
                <span class="text-blue-200">{{ __('Male') }} {{ $genderBreakdown['scheduled']['male'] }}</span>
                <span class="text-violet-200">{{ __('Female') }} {{ $genderBreakdown['scheduled']['female'] }}</span>
                @if ($genderBreakdown['scheduled']['unknown'] > 0)
                    <span>{{ __('Unknown') }} {{ $genderBreakdown['scheduled']['unknown'] }}</span>
                @endif
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

        <div id="offline-queue-banner" hidden class="rounded-2xl bg-orange-50 p-4 text-sm text-orange-700">
            <span data-queue-text></span>
            <button type="button" id="offline-sync-now" class="ml-2 font-semibold underline">{{ __('Sync now') }}</button>
        </div>

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
                        @can('mark_attendance')
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <form method="POST" action="{{ route('attendance.present', $dutySession) }}" class="js-attendance-form" data-offline-action="present" data-assignment-id="{{ $assignment->id }}">
                                    @csrf
                                    <input type="hidden" name="assignment_ids[]" value="{{ $assignment->id }}">
                                    <input type="hidden" name="return_to" value="pending">
                                    <button type="submit" class="w-full rounded-xl bg-emerald-600 py-2 text-xs font-semibold text-white hover:bg-emerald-700">{{ __('Present') }}</button>
                                </form>
                                <form method="POST" action="{{ route('attendance.absent', $dutySession) }}" class="js-attendance-form" data-offline-action="absent" data-assignment-id="{{ $assignment->id }}" data-confirm-message="{{ __('Mark this person Absent?') }}">
                                    @csrf
                                    <input type="hidden" name="assignment_id" value="{{ $assignment->id }}">
                                    <input type="hidden" name="return_to" value="pending">
                                    <button type="submit" class="w-full rounded-xl border border-red-200 py-2 text-xs font-semibold text-red-600">{{ __('Absent') }}</button>
                                </form>
                            </div>
                        @endcan
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
                        <form method="POST" action="{{ route('attendance.absent-all', $dutySession) }}" class="mt-3 grid grid-cols-2 gap-3 js-bulk-absent-form">
                            @csrf
                            <x-shell.button tone="warning" type="submit">{{ __('Confirm') }}</x-shell.button>
                            <button type="button" @click="confirming = false" class="w-full rounded-xl border border-slate-300 py-3 text-sm font-semibold text-slate-600">{{ __('Cancel') }}</button>
                        </form>
                    </x-shell.card>
                </div>
            @endif
        @endif
    </div>

    @if ($dutySession->isActive())
        <script>
        // Offline support for the Pending list: same engine as Live
        // Attendance (resources/js/offline.js), just applied to a full list
        // instead of a single search result. The list itself is whatever
        // the server last rendered — going offline doesn't re-fetch it, only
        // the mutation forms below get intercepted.
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.KGOffline) return;

            var sessionId = {{ $dutySession->id }};
            var userId = {{ auth()->id() }};
            var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            var offline = new window.KGOffline.OfflineAttendance({ sessionId: sessionId, userId: userId, csrfToken: csrfToken });
            window.kgOffline = offline;

            offline.provision().catch(function () {});
            offline.startAutoSync();

            var badge = document.getElementById('connectivity-badge');
            var banner = document.getElementById('offline-queue-banner');

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

            function queueOfflineFeedback(form) {
                var note = document.createElement('p');
                note.className = 'mt-2 text-xs font-semibold text-orange-600';
                note.textContent = '{{ __('Saved offline — will sync automatically when connection returns.') }}';
                form.after(note);
                form.querySelectorAll('button, input, select, textarea').forEach(function (el) { el.disabled = true; });
            }

            document.querySelectorAll('.js-attendance-form').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var action = form.dataset.offlineAction;
                    var assignmentId = parseInt(form.dataset.assignmentId, 10);

                    if (form.dataset.confirmMessage && !confirm(form.dataset.confirmMessage)) {
                        return;
                    }

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
                        offline.markAttendance(assignmentId, action, null).then(function () {
                            queueOfflineFeedback(form);
                            refreshUi();
                        });
                    });
                });
            });

            // Mark All Remaining Absent: queue one event per assignment_id
            // still rendered as pending in this list — the server already
            // processes each synced event independently, so this is
            // equivalent to the single bulk POST, just split into N events.
            document.querySelectorAll('.js-bulk-absent-form').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();

                    // Exclude rows already actioned this page-load (button
                    // disabled by queueOfflineFeedback after a Present/Absent
                    // click above) — otherwise a person just marked Present
                    // offline would also get queued Absent here.
                    var assignmentIds = Array.from(document.querySelectorAll('.js-attendance-form[data-offline-action="absent"]'))
                        .filter(function (f) { var btn = f.querySelector('button'); return !btn || !btn.disabled; })
                        .map(function (f) { return parseInt(f.dataset.assignmentId, 10); });

                    if (!assignmentIds.length) return;

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
                        Promise.all(assignmentIds.map(function (id) { return offline.markAttendance(id, 'absent', null); })).then(function () {
                            queueOfflineFeedback(form);
                            refreshUi();
                        });
                    });
                });
            });
        });
        </script>
    @endif
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header :title="'Command Center'" :subtitle="$dutySession->name" :back-url="route('sessions.show', $dutySession)">
            <x-slot:actions>
                <x-shell.badge :tone="$dutySession->statusTone()" dot>{{ $dutySession->status }}</x-shell.badge>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        <p class="flex items-center justify-end gap-1.5 text-right text-[11px] text-slate-400">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 kg-pulse-dot"></span>
            {{ __('Live') }} · {{ __('updated') }} <span data-updated>{{ now()->toIst()->format('H:i:s') }}</span>
        </p>

        <div class="kg-glass kg-enter relative overflow-hidden rounded-3xl p-6 text-center">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_-20%,rgba(15,30,61,0.08),transparent_60%)]"></div>
            <div class="relative">
                <p class="text-5xl font-bold tabular-nums text-navy-900" data-rate>{{ $counters['rate'] ?? 0 }}%</p>
                <p class="mt-1 text-xs font-semibold uppercase tracking-widest text-slate-400">{{ __('Attendance Rate') }}</p>
                <div class="mt-4 flex items-center justify-center gap-4 text-xs">
                    <span class="flex items-center gap-1.5 text-emerald-700"><span class="h-2 w-2 rounded-full bg-emerald-500"></span><span data-present class="font-semibold tabular-nums">{{ $counters['present'] }}</span> {{ __('Present') }}</span>
                    <span class="flex items-center gap-1.5 text-red-700"><span class="h-2 w-2 rounded-full bg-red-500"></span><span data-absent class="font-semibold tabular-nums">{{ $counters['absent'] }}</span> {{ __('Absent') }}</span>
                    <span class="flex items-center gap-1.5 text-orange-700"><span class="h-2 w-2 rounded-full bg-orange-500"></span><span data-pending class="font-semibold tabular-nums">{{ $counters['pending'] }}</span> {{ __('Pending') }}</span>
                </div>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-white/60">
                    <div class="kg-progress-fill h-2 rounded-full bg-gradient-to-r from-emerald-500 to-emerald-400" data-rate-bar style="width: {{ $counters['rate'] ?? 0 }}%"></div>
                </div>
            </div>
        </div>

        <div>
            <h2 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Departments') }}</h2>
            <div class="space-y-2" data-departments>
                @foreach ($departments as $dept)
                    <div class="kg-card-hover kg-enter kg-enter-{{ min($loop->iteration, 5) }} rounded-2xl border border-slate-100 bg-white p-3 shadow-sm">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-slate-900">{{ $dept->department_name }}</span>
                            <span class="font-semibold tabular-nums text-slate-700">{{ $dept->rate }}%</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="kg-progress-fill h-1.5 rounded-full bg-blue-500" style="width: {{ $dept->rate }}%"></div>
                        </div>
                    </div>
                @endforeach
                @if ($departments->isEmpty())
                    <x-shell.empty-state title="{{ __('No departments yet') }}" />
                @endif
            </div>
        </div>

        <div data-attention-wrap @if ($attention['count'] === 0) hidden @endif>
            <h2 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Attention Needed') }}</h2>
            <x-shell.card class="border-orange-200 bg-orange-50/60">
                <div class="flex items-start gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-orange-100 text-orange-600 kg-pulse-dot">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /></svg>
                    </span>
                    <div>
                        <p class="text-sm text-orange-800" data-attention-text>
                            {{ __(':count offline sync issue(s) — :conflict conflict, :rejected rejected, :mismatch operator mismatch.', [
                                'count' => $attention['count'], 'conflict' => $attention['conflict'], 'rejected' => $attention['rejected'], 'mismatch' => $attention['operator_mismatch'],
                            ]) }}
                        </p>
                        @can('view_audit_log')
                            <a href="{{ route('audit.index', ['session_id' => $dutySession->id, 'action' => 'sync_issue']) }}" class="mt-1 inline-block text-xs font-semibold text-orange-700 underline">
                                {{ __('Review in Audit Log') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </x-shell.card>
        </div>

        <div>
            <h2 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Recent Activity') }}</h2>
            <div class="space-y-1.5" data-activity>
                @forelse ($recentActivity as $item)
                    <div class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-xs shadow-sm">
                        <span class="font-mono tabular-nums text-slate-400">{{ $item['timestamp'] }}</span>
                        <span class="flex-1 text-slate-700">{{ $item['description'] }}</span>
                        <span class="text-slate-400">{{ $item['actor'] }}</span>
                    </div>
                @empty
                    <x-shell.empty-state title="{{ __('No activity yet') }}" />
                @endforelse
            </div>
        </div>
    </div>

    <script>
    (function () {
        var url = @json(route('sessions.command-center.data', $dutySession));
        var pollMs = 8000;

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function bump(el) {
            el.classList.remove('kg-count-pop');
            void el.offsetWidth; // restart animation
            el.classList.add('kg-count-pop');
        }

        function render(data) {
            var rateEl = document.querySelector('[data-rate]');
            if (rateEl.textContent !== (data.counters.rate ?? 0) + '%') { bump(rateEl); }
            rateEl.textContent = (data.counters.rate ?? 0) + '%';
            document.querySelector('[data-rate-bar]').style.width = (data.counters.rate ?? 0) + '%';
            document.querySelector('[data-present]').textContent = data.counters.present;
            document.querySelector('[data-absent]').textContent = data.counters.absent;
            document.querySelector('[data-pending]').textContent = data.counters.pending;
            document.querySelector('[data-updated]').textContent = new Date().toLocaleTimeString();

            var deptWrap = document.querySelector('[data-departments]');
            if (data.departments.length === 0) {
                deptWrap.innerHTML = '<div class="rounded-2xl border border-slate-100 bg-white p-5 text-center text-slate-400 shadow-sm">{{ __('No departments yet.') }}</div>';
            } else {
                deptWrap.innerHTML = data.departments.map(function (d) {
                    return '<div class="rounded-2xl border border-slate-100 bg-white p-3 shadow-sm">'
                        + '<div class="flex items-center justify-between text-sm"><span class="font-medium text-slate-900">' + esc(d.department_name) + '</span>'
                        + '<span class="font-semibold tabular-nums text-slate-700">' + d.rate + '%</span></div>'
                        + '<div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="kg-progress-fill h-1.5 rounded-full bg-blue-500" style="width:' + d.rate + '%"></div></div></div>';
                }).join('');
            }

            var attentionWrap = document.querySelector('[data-attention-wrap]');
            if (data.attention.count > 0) {
                attentionWrap.hidden = false;
                document.querySelector('[data-attention-text]').textContent =
                    data.attention.count + ' offline sync issue(s) — ' + data.attention.conflict + ' conflict, '
                    + data.attention.rejected + ' rejected, ' + data.attention.operator_mismatch + ' operator mismatch.';
            } else {
                attentionWrap.hidden = true;
            }

            var activityWrap = document.querySelector('[data-activity]');
            if (data.recentActivity.length === 0) {
                activityWrap.innerHTML = '<div class="rounded-2xl border border-slate-100 bg-white p-5 text-center text-slate-400 shadow-sm">{{ __('No activity yet.') }}</div>';
            } else {
                activityWrap.innerHTML = data.recentActivity.map(function (a, i) {
                    return '<div class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-xs shadow-sm' + (i === 0 ? ' kg-enter' : '') + '">'
                        + '<span class="font-mono tabular-nums text-slate-400">' + esc(a.timestamp) + '</span>'
                        + '<span class="flex-1 text-slate-700">' + esc(a.description) + '</span>'
                        + '<span class="text-slate-400">' + esc(a.actor) + '</span></div>';
                }).join('');
            }
        }

        function poll() {
            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (res) { return res.ok ? res.json() : Promise.reject(); })
                .then(render)
                .catch(function () {}); // a missed poll just tries again next tick — no error UI needed for a background refresh
        }

        var timer = setInterval(poll, pollMs);
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                clearInterval(timer);
            } else {
                poll();
                timer = setInterval(poll, pollMs);
            }
        });
    })();
    </script>
</x-app-layout>

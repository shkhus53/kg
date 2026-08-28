<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Analytics" :back-url="route('dashboard')">
            @include('analytics._tabs', ['active' => 'overview', 'from' => $from, 'to' => $to, 'sessionId' => $sessionId])
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        <x-shell.card>
            <form method="GET" action="{{ route('analytics.overview') }}" class="grid grid-cols-2 gap-3">
                <div>
                    <x-input-label for="from" :value="__('From')" />
                    <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$from" />
                </div>
                <div>
                    <x-input-label for="to" :value="__('To')" />
                    <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$to" />
                </div>
                <div class="col-span-2">
                    <x-input-label for="session_id" :value="__('Session (optional)')" />
                    <select id="session_id" name="session_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('All sessions in range') }}</option>
                        @foreach ($sessionOptions as $s)
                            <option value="{{ $s->id }}" @selected((string) $sessionId === (string) $s->id)>{{ $s->name }} ({{ $s->date->format('d M Y') }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2">
                    <x-shell.button tone="primary" type="submit">{{ __('Apply') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        @if ($scheduled === 0 && $extra === 0)
            <x-shell.card class="text-center text-slate-400">
                {{ __('No sessions in the selected period.') }}
            </x-shell.card>
        @else
            <div class="grid grid-cols-2 gap-3">
                <x-shell.stat-card :value="$scheduled" label="Total Scheduled" tone="blue">
                    <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1m8-4H6a2 2 0 0 0-2 2v16l4-2 4 2 4-2 4 2V6a2 2 0 0 0-2-2Z" /></svg></x-slot:icon>
                </x-shell.stat-card>
                <x-shell.stat-card :value="$present" label="Present" tone="green">
                    <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg></x-slot:icon>
                </x-shell.stat-card>
                <x-shell.stat-card :value="$absent" label="Absent" tone="red">
                    <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></x-slot:icon>
                </x-shell.stat-card>
                <x-shell.stat-card :value="$extra" label="Extra Present" tone="purple">
                    <x-slot:icon><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg></x-slot:icon>
                </x-shell.stat-card>
            </div>

            @if ($pending > 0)
                <x-shell.info-card>
                    {{ __(':count assignment(s) still Pending in this period (active sessions). Not counted as Present or Absent.', ['count' => $pending]) }}
                </x-shell.info-card>
            @endif

            <x-shell.card>
                <p class="text-xs font-medium text-slate-400">{{ __('Attendance Rate') }}</p>
                <p class="text-3xl font-bold text-emerald-600">{{ $rate !== null ? $rate.'%' : '—' }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ __('Present ÷ Total Scheduled — Extra Present excluded') }}</p>
            </x-shell.card>

            @if ($trend->isNotEmpty())
                <x-shell.card>
                    <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Attendance Trend') }}</h3>
                    <div class="space-y-2">
                        @foreach ($trend as $point)
                            <div class="flex items-center gap-3">
                                <div class="w-20 shrink-0 text-xs text-slate-400">{{ \Carbon\Carbon::parse($point->session_date)->format('d M') }}</div>
                                <div class="h-2 flex-1 rounded-full bg-slate-100">
                                    <div class="h-2 rounded-full bg-emerald-500" style="width: {{ $point->rate }}%"></div>
                                </div>
                                <div class="w-12 shrink-0 text-right text-xs font-semibold text-slate-700">{{ $point->rate }}%</div>
                            </div>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif

            @if ($departments->isNotEmpty())
                <x-shell.card>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-700">{{ __('Attendance by Department') }}</h3>
                        <a href="{{ route('analytics.departments', ['from' => $from, 'to' => $to, 'session_id' => $sessionId]) }}" class="text-xs font-semibold text-blue-600">{{ __('View All') }}</a>
                    </div>
                    <div class="space-y-2">
                        @foreach ($departments as $dept)
                            <div>
                                <div class="flex justify-between text-xs text-slate-600"><span>{{ $dept->department_name }}</span><span>{{ $dept->rate }}%</span></div>
                                <div class="mt-1 h-2 rounded-full bg-slate-100">
                                    <div class="h-2 rounded-full bg-blue-500" style="width: {{ $dept->rate }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-shell.card>
            @endif
        @endif
    </div>
</x-app-layout>

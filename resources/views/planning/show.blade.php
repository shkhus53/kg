<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="{{ $plan->event->name }}" :subtitle="$plan->miqaat->name" :back-url="route('planning.index')">
            <x-slot:actions>
                <x-shell.badge :tone="$plan->isFinalized() ? 'green' : 'gray'" dot>{{ $plan->isFinalized() ? 'Finalized' : 'Draft' }}</x-shell.badge>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5 lg:mx-auto lg:max-w-3xl" x-data="{
        departments: {{ Js::from($departments) }},
        get plannedTotal() { return this.departments.reduce((sum, d) => sum + Number(d.planned || 0), 0); },
        status(d) {
            const planned = Number(d.planned || 0), recommended = Number(d.recommended || 0);
            if (recommended > 0 && planned === 0) return 'missing_from_plan';
            if (recommended === 0) return planned > 0 ? 'over_planned' : 'adequately_planned';
            const diffPct = Math.abs(planned - recommended) / recommended;
            if (diffPct <= 0.1) return 'adequately_planned';
            return planned < recommended ? 'under_planned' : 'over_planned';
        },
        statusLabel: { adequately_planned: 'Adequately Planned', under_planned: 'Under-planned', over_planned: 'Over-planned', missing_from_plan: 'Missing from Plan' },
        statusTone: { adequately_planned: 'bg-emerald-100 text-emerald-700', under_planned: 'bg-orange-100 text-orange-700', over_planned: 'bg-blue-100 text-blue-700', missing_from_plan: 'bg-red-100 text-red-700' },
    }">
        @if (session('status'))
            <div class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif
        @if (session('flash_error'))
            <div class="rounded-2xl bg-red-50 p-4 text-sm text-red-700">{{ session('flash_error') }}</div>
        @endif

        <x-shell.card class="kg-enter">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Recommended vs Planned') }}</h3>
            <div class="grid grid-cols-3 gap-3">
                <x-shell.stat-card compact :value="$plan->recommended_total" label="Recommended HR" tone="blue" />
                <div class="kg-card-hover rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                    <div class="text-lg font-semibold leading-tight tabular-nums text-violet-600" x-text="plannedTotal"></div>
                    <div class="text-xs text-slate-500">{{ __('Planned HR') }}</div>
                </div>
                <div class="kg-card-hover rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm">
                    <div class="text-lg font-semibold leading-tight tabular-nums" x-text="plannedTotal - {{ $plan->recommended_total }}"></div>
                    <div class="text-xs text-slate-500">{{ __('Gap') }}</div>
                </div>
            </div>
            <p class="mt-2 text-[11px] text-slate-400">{{ __('Planned Date') }}: {{ $plan->planned_date->format('d M Y') }} &middot; {{ __('Venue') }}: {{ $plan->venue->name }}</p>
        </x-shell.card>

        <x-shell.card>
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Department Planning') }}</h4>
                <span class="text-xs text-slate-400">{{ __('Planned Total') }}: <span class="font-semibold tabular-nums text-slate-700" x-text="plannedTotal"></span></span>
            </div>

            @if (empty($departments))
                <x-shell.empty-state title="{{ __('No historical evidence for this event') }}" description="{{ __('Add planned departments manually once a duty list is available, or wait for future sessions to build history.') }}" />
            @else
                <form method="POST" action="{{ route('planning.update', $plan) }}" class="space-y-2">
                    @csrf
                    @method('PUT')

                    <template x-for="(dept, index) in departments" :key="dept.department_id">
                        <div class="rounded-xl border border-slate-100 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-900" x-text="dept.name"></p>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-1 text-[10px]">
                                        <span class="rounded-full bg-slate-100 px-1.5 py-0.5 font-semibold uppercase tracking-wide text-slate-500" x-text="dept.evidence + ' {{ __('evidence') }}'"></span>
                                        <template x-if="dept.extra_present_only">
                                            <span class="rounded-full bg-violet-100 px-1.5 py-0.5 font-semibold uppercase tracking-wide text-violet-700">{{ __('Extra-present history') }}</span>
                                        </template>
                                        <span class="rounded-full px-1.5 py-0.5 font-semibold uppercase tracking-wide" :class="statusTone[status(dept)]" x-text="statusLabel[status(dept)]"></span>
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-[10px] text-slate-400">{{ __('Recommended') }}</p>
                                    <p class="text-sm font-semibold tabular-nums text-slate-900" x-text="dept.recommended"></p>
                                </div>
                            </div>
                            <div class="mt-2 flex items-center gap-2">
                                <label class="text-xs text-slate-400" :for="'planned_' + dept.department_id">{{ __('Planned') }}</label>
                                <input
                                    type="number" min="0" :id="'planned_' + dept.department_id" :name="'planned[' + dept.department_id + ']'"
                                    x-model.number="dept.planned"
                                    :disabled="{{ $plan->isFinalized() ? 'true' : 'false' }}"
                                    class="kg-tap w-24 rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400"
                                >
                            </div>
                        </div>
                    </template>

                    @unless ($plan->isFinalized())
                        <x-shell.button tone="outline" type="submit">{{ __('Save Planned Quantities') }}</x-shell.button>
                    @endunless
                </form>
            @endif
        </x-shell.card>

        @if ($plan->dutySession)
            <x-shell.button tone="success" :href="route('sessions.show', $plan->dutySession)">{{ __('View Duty Session') }}</x-shell.button>
        @elseif (! empty($departments))
            <x-shell.card>
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Create Duty Session') }}</h4>
                <p class="mb-3 text-xs text-slate-500">{{ __('Creates the actual Duty Session using this plan\'s Miqaat, Event, Venue and Date, so you can upload the duty list.') }}</p>
                <form method="POST" action="{{ route('sessions.store') }}">
                    @csrf
                    <input type="hidden" name="miqaat_id" value="{{ $plan->miqaat_id }}">
                    <input type="hidden" name="event_id" value="{{ $plan->event_id }}">
                    <input type="hidden" name="venue_id" value="{{ $plan->venue_id }}">
                    <input type="hidden" name="date" value="{{ $plan->planned_date->format('Y-m-d') }}">
                    <input type="hidden" name="h_year" value="{{ $plan->h_year }}">
                    <input type="hidden" name="event_plan_id" value="{{ $plan->id }}">
                    <x-shell.button tone="primary" type="submit">{{ __('Create Duty Session from this Plan') }}</x-shell.button>
                </form>
            </x-shell.card>
        @endif

        @if (! empty($plan->forecast_snapshot['explanation'] ?? null))
            <div x-data="{ open: false }" class="border-t border-slate-100 pt-3">
                <button type="button" @click="open = !open" class="kg-tap flex w-full items-center justify-between text-left text-xs font-semibold text-blue-600">
                    {{ __('Forecast basis (frozen at plan creation)') }}
                    <svg class="h-4 w-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" /></svg>
                </button>
                <div x-show="open" x-cloak x-transition class="mt-2 space-y-1.5 text-xs text-slate-600">
                    <ul class="list-disc space-y-1 pl-4">
                        @foreach ($plan->forecast_snapshot['explanation'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>

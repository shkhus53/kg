<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Event Planning">
            <x-slot:actions>
                <a href="{{ route('planning.create') }}" class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                </a>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        @if (session('status'))
            <div class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        @if ($plans->isEmpty())
            <x-shell.empty-state
                title="{{ __('No future plans yet') }}"
                description="{{ __('Plan a future event to see the Phase 3 forecast before you commit to a duty session.') }}"
            >
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4" /></svg>
                </x-slot:icon>
                <x-shell.button tone="primary" href="{{ route('planning.create') }}">{{ __('Plan an Event') }}</x-shell.button>
            </x-shell.empty-state>
        @else
            <div class="lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 space-y-2 xl:grid-cols-3">
                @foreach ($plans as $plan)
                    <a href="{{ route('planning.show', $plan) }}"
                       class="kg-card-hover kg-tap flex flex-col gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900">{{ $plan->event->name }}</p>
                                <p class="truncate text-xs text-slate-400">
                                    {{ $plan->planned_date->format('d M Y') }} &middot; {{ $plan->venue->name }}
                                </p>
                            </div>
                            <x-shell.badge :tone="$plan->isFinalized() ? 'green' : 'gray'" dot>{{ $plan->status }}</x-shell.badge>
                        </div>
                        <div class="flex items-center justify-between text-xs text-slate-500">
                            <span>{{ __('Recommended') }}: <span class="font-semibold tabular-nums text-slate-700">{{ $plan->recommended_total }}</span></span>
                            <span>{{ __('Planned') }}: <span class="font-semibold tabular-nums text-slate-700">{{ $plan->planned_total }}</span></span>
                        </div>
                    </a>
                @endforeach
            </div>

            <div>{{ $plans->links() }}</div>
        @endif
    </div>
</x-app-layout>

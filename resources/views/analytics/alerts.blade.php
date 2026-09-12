<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Analytics" :back-url="route('dashboard')">
            @include('analytics._tabs', ['active' => 'alerts', 'from' => $from, 'to' => $to])
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        <x-shell.card class="lg:sticky lg:top-4 lg:z-10">
            <form method="GET" action="{{ route('analytics.alerts') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-[repeat(2,minmax(0,1fr))_auto] lg:items-end">
                <div>
                    <x-input-label for="from" :value="__('From')" />
                    <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$from" />
                </div>
                <div>
                    <x-input-label for="to" :value="__('To')" />
                    <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$to" />
                </div>
                <div class="col-span-2 lg:col-span-1">
                    <x-shell.button tone="primary" type="submit">{{ __('Apply') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        @if ($highAlerts->isEmpty() && $mediumAlerts->isEmpty())
            <x-shell.empty-state
                title="{{ __('No active alerts') }}"
                description="{{ __('Every deterministic rule (pending assignments, planning variance, import quality, reopens, Extra Present, corrections, attendance rate) came back clean for this period.') }}"
            >
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                </x-slot:icon>
            </x-shell.empty-state>
        @else
            @if ($highAlerts->isNotEmpty())
                <div>
                    <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold text-red-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /></svg>
                        {{ __('High Priority') }} ({{ $highAlerts->count() }})
                    </h2>
                    <div class="space-y-2">
                        @foreach ($highAlerts as $alert)
                            <a href="{{ $alert['link'] }}" class="kg-card-hover kg-tap block rounded-2xl border border-red-100 bg-red-50 p-4">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-semibold text-red-700">{{ $alert['title'] }}</p>
                                    <x-shell.badge tone="red">{{ __('High') }}</x-shell.badge>
                                </div>
                                <p class="mt-1 text-sm text-slate-700">{{ $alert['reason'] }}</p>
                                <p class="mt-2 text-[11px] text-slate-400">{{ __('Rule') }}: {{ $alert['threshold'] }}</p>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($mediumAlerts->isNotEmpty())
                <div>
                    <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold text-orange-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 8v4m0 4h.01" /></svg>
                        {{ __('Medium Priority') }} ({{ $mediumAlerts->count() }})
                    </h2>
                    <div class="space-y-2">
                        @foreach ($mediumAlerts as $alert)
                            <a href="{{ $alert['link'] }}" class="kg-card-hover kg-tap block rounded-2xl border border-orange-100 bg-orange-50 p-4">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-semibold text-orange-700">{{ $alert['title'] }}</p>
                                    <x-shell.badge tone="orange">{{ __('Medium') }}</x-shell.badge>
                                </div>
                                <p class="mt-1 text-sm text-slate-700">{{ $alert['reason'] }}</p>
                                <p class="mt-2 text-[11px] text-slate-400">{{ __('Rule') }}: {{ $alert['threshold'] }}</p>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-app-layout>

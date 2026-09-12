<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Master Data" :back-url="route('dashboard')" />
    </x-slot>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <a href="{{ route('masters.departments.index') }}" class="kg-card-hover kg-tap rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <span class="mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l8-4 8 4v14M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h1m4 0h1" /></svg>
            </span>
            <p class="text-sm font-semibold text-slate-900">{{ __('Departments') }}</p>
            <p class="text-xs text-slate-400">{{ __(':count total', ['count' => $departmentCount]) }}</p>
        </a>

        <a href="{{ route('masters.miqaats.index') }}" class="kg-card-hover kg-tap rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <span class="mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 21h14a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1Z" /></svg>
            </span>
            <p class="text-sm font-semibold text-slate-900">{{ __('Miqaats') }}</p>
            <p class="text-xs text-slate-400">{{ __(':count total', ['count' => $miqaatCount]) }}</p>
        </a>

        <a href="{{ route('masters.events.index') }}" class="kg-card-hover kg-tap rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <span class="mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-orange-50 text-orange-500">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1m8-4H6a2 2 0 0 0-2 2v16l4-2 4 2 4-2 4 2V6a2 2 0 0 0-2-2Z" /></svg>
            </span>
            <p class="text-sm font-semibold text-slate-900">{{ __('Events') }}</p>
            <p class="text-xs text-slate-400">{{ __(':count total', ['count' => $eventCount]) }}</p>
        </a>

        <a href="{{ route('masters.venues.index') }}" class="kg-card-hover kg-tap rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <span class="mb-2 flex h-9 w-9 items-center justify-center rounded-full bg-violet-50 text-violet-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-6.1-7-11a7 7 0 1 1 14 0c0 4.9-7 11-7 11Z" /><circle cx="12" cy="10" r="2.5" /></svg>
            </span>
            <p class="text-sm font-semibold text-slate-900">{{ __('Venues') }}</p>
            <p class="text-xs text-slate-400">{{ __(':count total', ['count' => $venueCount]) }}</p>
        </a>
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Reports" :back-url="route('dashboard')" />
    </x-slot>

    <div class="space-y-5">
        <div class="lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 space-y-5">
            <x-shell.card hover class="flex flex-col">
                <span class="mb-3 flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4M5 21h14a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1Z" /></svg>
                </span>
                <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Department Report') }}</h3>
                <p class="mb-3 flex-1 text-xs text-slate-400">{{ __('All departments across a date range, or one session.') }}</p>
                <x-shell.button tone="primary" href="{{ route('reports.department') }}">{{ __('Open') }}</x-shell.button>
            </x-shell.card>

            <x-shell.card hover class="flex flex-col">
                <span class="mb-3 flex h-9 w-9 items-center justify-center rounded-full bg-violet-50 text-violet-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm-8 8a6 6 0 0 0-6 6h20a6 6 0 0 0-6-6H8Z" /></svg>
                </span>
                <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Khidmatguzar Report') }}</h3>
                <p class="mb-3 flex-1 text-xs text-slate-400">{{ __('Find a person in the Directory, then open their Report from their profile.') }}</p>
                <x-shell.button tone="outline" href="{{ route('analytics.profile-search') }}">{{ __('Open Directory') }}</x-shell.button>
            </x-shell.card>
        </div>

        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Session Reports') }}</h3>
            @if ($sessions->isEmpty())
                <x-shell.empty-state title="{{ __('No duty sessions yet') }}" />
            @else
                <div class="space-y-2 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 xl:grid-cols-3">
                    @foreach ($sessions as $session)
                        <a href="{{ route('reports.session', $session) }}" class="kg-card-hover kg-tap flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900">{{ $session->name }}</p>
                                <p class="text-xs text-slate-400">{{ $session->date->format('d M Y') }}</p>
                            </div>
                            <x-shell.badge :tone="$session->statusTone()">{{ $session->status }}</x-shell.badge>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

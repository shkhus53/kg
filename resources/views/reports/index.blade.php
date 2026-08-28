<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Reports" :back-url="route('dashboard')" />
    </x-slot>

    <div class="space-y-5">
        <x-shell.card>
            <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Department Report') }}</h3>
            <p class="mb-3 text-xs text-slate-400">{{ __('All departments across a date range, or one session.') }}</p>
            <x-shell.button tone="primary" href="{{ route('reports.department') }}">{{ __('Open') }}</x-shell.button>
        </x-shell.card>

        <x-shell.card>
            <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Khidmatguzar Report') }}</h3>
            <p class="mb-3 text-xs text-slate-400">{{ __('Find a person in the Directory, then open their Report from their profile.') }}</p>
            <x-shell.button tone="outline" href="{{ route('analytics.profile-search') }}">{{ __('Open Directory') }}</x-shell.button>
        </x-shell.card>

        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Session Reports') }}</h3>
            @if ($sessions->isEmpty())
                <x-shell.card class="text-center text-slate-400">{{ __('No duty sessions yet.') }}</x-shell.card>
            @else
                <div class="space-y-2">
                    @foreach ($sessions as $session)
                        <a href="{{ route('reports.session', $session) }}" class="flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
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

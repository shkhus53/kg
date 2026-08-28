<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Duty Sessions">
            <x-slot:actions>
                @if (auth()->user()->canManageSessions())
                    <a href="{{ route('sessions.create') }}" class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    </a>
                @endif
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        @if (session('status'))
            <div class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        @foreach (['flash_success' => 'green', 'flash_info' => 'blue', 'flash_warning' => 'orange', 'flash_error' => 'red'] as $key => $tone)
            @if (session($key))
                @php $bg = ['green' => 'bg-emerald-50 text-emerald-700', 'blue' => 'bg-blue-50 text-blue-700', 'orange' => 'bg-orange-50 text-orange-700', 'red' => 'bg-red-50 text-red-700'][$tone]; @endphp
                <div class="rounded-2xl {{ $bg }} p-4 text-sm">{{ session($key) }}</div>
            @endif
        @endforeach

        @if ($sessions->isEmpty())
            <x-shell.card class="text-center text-slate-400">
                {{ __('No duty sessions yet.') }}
                @if (auth()->user()->canManageSessions())
                    <a href="{{ route('sessions.create') }}" class="mt-2 block font-semibold text-blue-600">{{ __('Create the first one →') }}</a>
                @endif
            </x-shell.card>
        @else
            <div class="space-y-2">
                @foreach ($sessions as $session)
                    <a href="{{ route('sessions.show', $session) }}" class="flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-900">{{ $session->name }}</p>
                            <p class="text-xs text-slate-400">{{ $session->date->format('d M Y') }} &middot; {{ __('created') }} {{ $session->created_at->format('d M Y') }}</p>
                        </div>
                        <x-shell.badge :tone="$session->statusTone()">{{ $session->status }}</x-shell.badge>
                    </a>
                @endforeach
            </div>

            <div>{{ $sessions->links() }}</div>
        @endif
    </div>
</x-app-layout>

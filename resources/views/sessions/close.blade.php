<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Close Session" :subtitle="$dutySession->name.' · '.$dutySession->date->format('d M Y')" :back-url="route('sessions.show', $dutySession)" />
    </x-slot>

    <div class="space-y-5">
        @if (session('status_error'))
            <div class="rounded-2xl bg-red-50 p-4 text-sm text-red-700">{{ session('status_error') }}</div>
        @endif

        @if ($dutySession->isClosed())
            <x-shell.card class="text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-500">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                </span>
                <p class="mt-3 text-lg font-semibold text-slate-900">{{ __('Session Closed') }}</p>
                <p class="mt-1 text-sm text-slate-400">
                    {{ $dutySession->closed_at?->format('d M Y H:i') }}
                    @if ($dutySession->closedBy) &middot; {{ $dutySession->closedBy->name }} @endif
                </p>
            </x-shell.card>
        @elseif ($pending > 0)
            <x-shell.card class="text-center border-orange-100 bg-orange-50/40">
                <p class="font-semibold text-orange-700">{{ __(':count pending assignment(s) remain.', ['count' => $pending]) }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('Resolve them before closing this session.') }}</p>
                <div class="mt-4">
                    <x-shell.button tone="warning" href="{{ route('attendance.shell.pending', $dutySession) }}">{{ __('Go to Pending Review') }}</x-shell.button>
                </div>
            </x-shell.card>
        @else
            <x-shell.card class="text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-500">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                </span>
                <p class="mt-3 text-lg font-semibold text-slate-900">{{ __('Ready to Close Session?') }}</p>
                <p class="mt-1 text-sm text-slate-400">{{ __('Once closed, attendance records will be locked and cannot be edited.') }}</p>
            </x-shell.card>
        @endif

        <x-shell.card>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Total Scheduled') }}</dt><dd class="font-semibold text-slate-900">{{ $scheduled }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Present') }}</dt><dd class="font-semibold text-emerald-600">{{ $present }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Absent') }}</dt><dd class="font-semibold text-red-600">{{ $absent }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Extra Present') }}</dt><dd class="font-semibold text-violet-600">{{ $extra }}</dd></div>
            </dl>

            <div class="mt-4 border-t border-slate-100 pt-4 text-center">
                <p class="text-xs font-medium text-slate-400">{{ __('Attendance Rate') }}</p>
                <p class="text-3xl font-bold text-emerald-600">{{ $rate }}%</p>
            </div>
        </x-shell.card>

        @if ($dutySession->isActive() && $pending === 0 && auth()->user()->canManageSessions())
            <form method="POST" action="{{ route('sessions.close', $dutySession) }}" onsubmit="return confirm('{{ __('Close and lock this session? Attendance records cannot be edited afterward.') }}')">
                @csrf
                <x-shell.button tone="success" type="submit">{{ __('Close & Lock Session') }}</x-shell.button>
            </form>
        @endif
    </div>
</x-app-layout>

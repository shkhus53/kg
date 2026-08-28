<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header :title="$dutySession->name" :subtitle="$dutySession->date->format('d M Y')" :back-url="route('sessions.index')">
            <x-slot:actions>
                <x-shell.badge :tone="$dutySession->statusTone()">{{ $dutySession->status }}</x-shell.badge>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        @if (session('status'))
            <div class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif
        @if (session('status_error'))
            <div class="rounded-2xl bg-red-50 p-4 text-sm text-red-700">{{ session('status_error') }}</div>
        @endif

        @if ($dutySession->status === 'draft' && auth()->user()->canManageSessions())
            <x-shell.info-card>
                {{ __('This session is in Draft. Attendance marking is disabled until it is activated.') }}
                <form method="POST" action="{{ route('sessions.activate', $dutySession) }}" class="mt-3" onsubmit="return confirm('Activate this session? Attendance marking will open.')">
                    @csrf
                    <x-shell.button tone="primary" type="submit">{{ __('Activate Session') }}</x-shell.button>
                </form>
            </x-shell.info-card>
        @endif

        @if ($dutySession->isClosed())
            <x-shell.info-card class="bg-slate-100 text-slate-600">
                {{ __('This session is closed and locked.') }}
                {{ $dutySession->closed_at?->format('d M Y H:i') }}
                @if ($dutySession->closedBy) &middot; {{ $dutySession->closedBy->name }} @endif
            </x-shell.info-card>
        @endif

        <x-shell.card>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-slate-400">{{ __('HYear') }}</dt>
                    <dd class="font-medium text-slate-900">{{ $dutySession->h_year ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">{{ __('Miqaat') }}</dt>
                    <dd class="font-medium text-slate-900">{{ $dutySession->miqaat ?: '—' }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-slate-400">{{ __('Remarks') }}</dt>
                    <dd class="font-medium text-slate-900 whitespace-pre-line">{{ $dutySession->remarks ?: '—' }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-slate-400">{{ __('Created') }}</dt>
                    <dd class="font-medium text-slate-900">{{ $dutySession->created_at->format('d M Y H:i') }}</dd>
                </div>
            </dl>
        </x-shell.card>

        <div class="grid grid-cols-2 gap-3">
            @if (auth()->user()->canManageSessions() && in_array($dutySession->status, ['draft', 'active'], true))
                <x-shell.button tone="primary" href="{{ route('sessions.imports.create', $dutySession) }}">
                    {{ __('Import Duty List') }}
                </x-shell.button>
            @endif
            <x-shell.button tone="outline" href="{{ route('attendance.shell.list', $dutySession) }}">
                {{ __('Attendance List') }}
            </x-shell.button>
            @if ($dutySession->isActive() && auth()->user()->canManageSessions())
                <x-shell.button tone="primary" href="{{ route('attendance.shell.live', $dutySession) }}">
                    {{ __('Live Attendance') }}
                </x-shell.button>
                <x-shell.button tone="warning" href="{{ route('attendance.shell.pending', $dutySession) }}">
                    {{ __('End of Day Review') }}
                </x-shell.button>
            @endif
        </div>

        <div>
            <h2 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Import Batches') }}</h2>

            @if ($dutySession->importBatches->isEmpty())
                <x-shell.card class="text-center text-slate-400">{{ __('No files imported yet.') }}</x-shell.card>
            @else
                <div class="space-y-2">
                    @foreach ($dutySession->importBatches->sortByDesc('id') as $batch)
                        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-900">{{ $batch->original_filename }}</p>
                                    <p class="text-xs text-slate-400">{{ $batch->uploadedBy->name }} &middot; {{ $batch->created_at->format('d M Y H:i') }}</p>
                                </div>
                                <x-shell.badge :tone="$batch->status === 'completed' ? 'green' : 'red'">{{ $batch->status }}</x-shell.badge>
                            </div>
                            <div class="mt-3 grid grid-cols-4 gap-2 text-center text-xs">
                                <div>
                                    <p class="font-semibold text-slate-900">{{ $batch->total_rows }}</p>
                                    <p class="text-slate-400">{{ __('Source') }}</p>
                                </div>
                                <div>
                                    <p class="font-semibold text-emerald-600">{{ $batch->valid_rows }}</p>
                                    <p class="text-slate-400">{{ __('Created') }}</p>
                                </div>
                                <div>
                                    <p class="font-semibold text-orange-500">{{ $batch->exact_duplicate_rows + $batch->cross_batch_duplicate_rows }}</p>
                                    <p class="text-slate-400">{{ __('Duplicates') }}</p>
                                </div>
                                <div>
                                    <p class="font-semibold text-red-500">{{ $batch->invalid_rows }}</p>
                                    <p class="text-slate-400">{{ __('Invalid') }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Import Center" subtitle="Every duty-list import, across every session" />
    </x-slot>

    <div class="space-y-5">
        @if ($batches->isEmpty())
            <x-shell.empty-state
                title="{{ __('No imports yet') }}"
                description="{{ __('Duty-list imports will appear here once uploaded from a session.') }}"
            >
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9A5.5 5.5 0 0 1 17 8a4.5 4.5 0 0 1 .5 9H7Z" /></svg>
                </x-slot:icon>
            </x-shell.empty-state>
        @else
            <div class="space-y-2">
                @foreach ($batches as $batch)
                    <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $batch->original_filename }}</p>
                                <p class="truncate text-xs text-slate-400">
                                    {{ $batch->dutySession?->event?->name ?? $batch->dutySession?->name }}
                                    &middot; {{ $batch->dutySession?->date?->format('d M Y') }}
                                    &middot; {{ __('by') }} {{ $batch->uploadedBy?->name }}
                                    &middot; {{ $batch->created_at->toIst()->format('d M Y H:i') }}
                                </p>
                            </div>
                            <x-shell.badge :tone="$batch->displayStatus() === 'Imported' ? 'green' : 'orange'" dot>{{ $batch->displayStatus() }}</x-shell.badge>
                        </div>

                        <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs sm:grid-cols-6">
                            <div><p class="font-semibold tabular-nums text-slate-900">{{ $batch->total_rows }}</p><p class="text-slate-400">{{ __('Total') }}</p></div>
                            <div><p class="font-semibold tabular-nums text-emerald-600">{{ $batch->valid_rows }}</p><p class="text-slate-400">{{ __('Valid') }}</p></div>
                            <div><p class="font-semibold tabular-nums text-red-600">{{ $batch->invalid_rows }}</p><p class="text-slate-400">{{ __('Invalid') }}</p></div>
                            <div><p class="font-semibold tabular-nums text-blue-600">{{ $batch->new_khidmatguzars }}</p><p class="text-slate-400">{{ __('New KG') }}</p></div>
                            <div><p class="font-semibold tabular-nums text-orange-600">{{ $batch->updated_khidmatguzars }}</p><p class="text-slate-400">{{ __('Updated') }}</p></div>
                            <div><p class="font-semibold tabular-nums text-slate-500">{{ $batch->exact_duplicate_rows + $batch->cross_batch_duplicate_rows }}</p><p class="text-slate-400">{{ __('Duplicates') }}</p></div>
                        </div>

                        <div class="mt-3 flex gap-3 border-t border-slate-100 pt-3 text-xs font-semibold">
                            <a href="{{ route('sessions.show', $batch->duty_session_id) }}" class="text-blue-600">{{ __('View Session') }}</a>
                            <a href="{{ route('sessions.imports.diff', [$batch->duty_session_id, $batch->id]) }}" class="text-blue-600">{{ __('View Master-Data Changes') }}</a>
                        </div>
                    </div>
                @endforeach
            </div>

            <div>{{ $batches->links() }}</div>
        @endif
    </div>
</x-app-layout>

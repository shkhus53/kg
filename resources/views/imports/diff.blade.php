<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Import Diff" :subtitle="$importBatch->original_filename" :back-url="route('sessions.show', $dutySession)">
            <x-shell.steps :steps="['Upload', 'Preview', 'Changes', 'Result']" current="Changes" />
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        <x-shell.card>
            <dl class="grid grid-cols-2 gap-3 text-sm lg:grid-cols-5">
                <div><dt class="text-slate-400">{{ __('Uploaded') }}</dt><dd class="font-medium text-slate-900">{{ $importBatch->created_at->toIst()->format('d M Y H:i') }}</dd></div>
                <div><dt class="text-slate-400">{{ __('By') }}</dt><dd class="font-medium text-slate-900">{{ $importBatch->uploadedBy->name }}</dd></div>
                <div><dt class="text-slate-400">{{ __('New Khidmatguzars') }}</dt><dd class="font-semibold tabular-nums text-emerald-600">{{ $importBatch->new_khidmatguzars }}</dd></div>
                <div><dt class="text-slate-400">{{ __('Updated') }}</dt><dd class="font-semibold tabular-nums text-blue-600">{{ $importBatch->updated_khidmatguzars }}</dd></div>
                <div><dt class="text-slate-400">{{ __('Unchanged') }}</dt><dd class="font-semibold tabular-nums text-slate-500">{{ $importBatch->unchanged_khidmatguzars }}</dd></div>
            </dl>
        </x-shell.card>

        @if ($changes->isEmpty())
            <x-shell.empty-state title="{{ __('This import did not change any existing master-data field') }}" description="{{ __('Either every person was new, or every re-imported field was blank — blank cells never overwrite existing values.') }}">
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                </x-slot:icon>
            </x-shell.empty-state>
        @else
            <div>
                <h2 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Master-Data Changes') }} ({{ $changes->count() }} {{ __('people') }})</h2>
                <div class="space-y-3 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 xl:grid-cols-3">
                    @foreach ($changes as $khidmatguzarId => $fieldChanges)
                        @php $person = $fieldChanges->first()->khidmatguzar; @endphp
                        <x-shell.card hover>
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <a href="{{ route('analytics.profile', $khidmatguzarId) }}" class="text-sm font-semibold text-slate-900 hover:underline">
                                        {{ $person->full_name ?? 'ITS '.$fieldChanges->first()->khidmatguzar_id }}
                                    </a>
                                    <p class="text-xs text-slate-400">{{ __('ITS') }}: {{ $person->its_id ?? '—' }}</p>
                                </div>
                                <x-shell.badge tone="blue">{{ trans_choice(':count field|:count fields', $fieldChanges->count(), ['count' => $fieldChanges->count()]) }}</x-shell.badge>
                            </div>
                            <div class="mt-3 space-y-1.5 text-sm">
                                @foreach ($fieldChanges as $change)
                                    <div class="rounded-lg bg-blue-50 px-3 py-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-blue-700/70">{{ str_replace('_', ' ', $change->field) }}</p>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs">
                                            <span class="rounded bg-white px-1.5 py-0.5 text-slate-400 line-through">{{ $change->old_value ?: '—' }}</span>
                                            <svg class="h-3 w-3 shrink-0 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0-4 4m4-4H3" /></svg>
                                            <span class="rounded bg-blue-100 px-1.5 py-0.5 font-semibold text-blue-700">{{ $change->new_value }}</span>
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </x-shell.card>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-app-layout>

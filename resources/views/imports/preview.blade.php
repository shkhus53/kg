<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Upload Summary" :subtitle="'Session: '.$dutySession->date->format('d M Y')" :back-url="route('sessions.imports.create', $dutySession)">
            <x-shell.steps :steps="['Upload', 'Preview', 'Changes', 'Result']" current="Preview" />
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">

        <x-shell.card class="kg-enter">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1m8-4H6a2 2 0 0 0-2 2v16l4-2 4 2 4-2 4 2V6a2 2 0 0 0-2-2Z" /></svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-medium text-slate-400">{{ __('File Details') }}</p>
                    <p class="truncate text-sm font-semibold text-slate-900">{{ $filename }}</p>
                </div>
            </div>
        </x-shell.card>

        <div class="lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 space-y-5">
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Summary') }}</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('Total Rows') }}</dt><dd class="font-semibold tabular-nums text-slate-900">{{ $preview['total_rows'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('New Assignments') }}</dt><dd class="font-semibold tabular-nums text-emerald-600">{{ $preview['valid_rows'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('Unique ITS Numbers') }}</dt><dd class="font-semibold tabular-nums text-slate-900">{{ $preview['unique_its_count'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('Departments (new / existing)') }}</dt><dd class="font-semibold tabular-nums text-slate-900">{{ $preview['new_departments'] }} / {{ $preview['existing_departments'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-red-500">{{ __('Invalid Rows') }}</dt><dd class="font-semibold tabular-nums text-red-600">{{ count($preview['invalid_rows']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-amber-500">{{ __('Within-File Duplicates') }}</dt><dd class="font-semibold tabular-nums text-amber-600">{{ count($preview['exact_duplicate_rows']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-orange-500">{{ __('Already in an Earlier Batch') }}</dt><dd class="font-semibold tabular-nums text-orange-600">{{ count($preview['cross_batch_duplicate_rows']) }}</dd></div>
                </dl>
            </x-shell.card>

            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Khidmatguzars in this file') }}</h3>
                <div class="grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="kg-card-hover rounded-xl bg-emerald-50 p-2">
                        <p class="text-lg font-bold tabular-nums text-emerald-600">{{ $preview['new_khidmatguzars'] }}</p>
                        <p class="text-slate-500">{{ __('New') }}</p>
                    </div>
                    <div class="kg-card-hover rounded-xl bg-blue-50 p-2">
                        <p class="text-lg font-bold tabular-nums text-blue-600">{{ $preview['updated_khidmatguzars'] }}</p>
                        <p class="text-slate-500">{{ __('Updated') }}</p>
                    </div>
                    <div class="kg-card-hover rounded-xl bg-slate-100 p-2">
                        <p class="text-lg font-bold tabular-nums text-slate-600">{{ $preview['unchanged_khidmatguzars'] }}</p>
                        <p class="text-slate-500">{{ __('Unchanged') }}</p>
                    </div>
                </div>
                <p class="mt-2 text-[11px] text-slate-400">{{ __('Blank cells in this file never overwrite an existing value — only fields with an actual non-blank change are listed below.') }}</p>
            </x-shell.card>
        </div>

        @if (! empty($preview['changed_khidmatguzars']))
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-blue-700">{{ __('Master-Data Changes — will be applied on confirm') }}</h3>
                <div class="max-h-72 space-y-2 overflow-y-auto text-sm">
                    @foreach ($preview['changed_khidmatguzars'] as $change)
                        <div class="rounded-lg bg-blue-50 px-3 py-2">
                            <p class="font-medium text-slate-800">{{ $change['full_name'] }} <span class="text-xs font-normal text-slate-400">({{ __('ITS') }} {{ $change['its_id'] }})</span></p>
                            <div class="mt-1 space-y-0.5">
                                @foreach ($change['changes'] as $field => $fieldChange)
                                    <p class="text-xs text-slate-600">
                                        <span class="font-medium capitalize">{{ str_replace('_', ' ', $field) }}</span>:
                                        <span class="text-slate-400 line-through">{{ $fieldChange['old'] ?: '—' }}</span>
                                        <span class="mx-1">→</span>
                                        <span class="font-semibold text-blue-700">{{ $fieldChange['new'] }}</span>
                                    </p>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>
        @endif

        <x-shell.card>
            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Column Mapping') }}</h3>
            <ul class="space-y-2 text-sm">
                @foreach ([['ITS ID', 'ITS Number'], ['FullName', 'Full Name'], ['Venue Name', 'Department']] as [$src, $dst])
                    <li class="flex items-center justify-between">
                        <span class="text-slate-500">{{ $src }} <span class="text-slate-300">→</span> {{ $dst }}</span>
                        <svg class="h-4 w-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" /></svg>
                    </li>
                @endforeach
            </ul>
        </x-shell.card>

        @if (count($preview['invalid_rows']) > 0)
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-red-600">{{ __('Invalid Rows — will be skipped') }}</h3>
                <div class="max-h-60 space-y-2 overflow-y-auto text-sm">
                    @foreach ($preview['invalid_rows'] as $err)
                        <div class="flex justify-between rounded-lg bg-red-50 px-3 py-2">
                            <span class="text-slate-600">{{ __('Row') }} {{ $err['row_number'] }} @if($err['value']) &middot; {{ $err['value'] }} @endif</span>
                            <span class="font-medium text-red-600">{{ $err['error'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>
        @endif

        @if (count($preview['exact_duplicate_rows']) > 0)
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-amber-600">{{ __('Within-File Duplicates — will be skipped') }}</h3>
                <div class="max-h-60 space-y-2 overflow-y-auto text-sm">
                    @foreach ($preview['exact_duplicate_rows'] as $dup)
                        <div class="flex justify-between rounded-lg bg-amber-50 px-3 py-2">
                            <span class="text-slate-600">{{ __('Row') }} {{ $dup['row_number'] }} @if($dup['value']) &middot; {{ $dup['value'] }} @endif</span>
                            <span class="font-medium text-amber-600">{{ $dup['error'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>
        @endif

        @if (count($preview['cross_batch_duplicate_rows']) > 0)
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-orange-600">{{ __('Already Imported Earlier — will be skipped') }}</h3>
                <div class="max-h-60 space-y-2 overflow-y-auto text-sm">
                    @foreach ($preview['cross_batch_duplicate_rows'] as $dup)
                        <div class="flex justify-between rounded-lg bg-orange-50 px-3 py-2">
                            <span class="text-slate-600">{{ __('Row') }} {{ $dup['row_number'] }} @if($dup['value']) &middot; {{ $dup['value'] }} @endif</span>
                            <span class="font-medium text-orange-600">{{ $dup['error'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-shell.card>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <x-shell.button tone="outline" href="{{ route('sessions.imports.create', $dutySession) }}">{{ __('Back') }}</x-shell.button>
            <form method="POST" action="{{ route('sessions.imports.confirm', [$dutySession, $token]) }}" x-data="{ submitting: false }" @submit="submitting = true">
                @csrf
                <x-shell.button tone="primary" type="submit" :disabled="$preview['valid_rows'] === 0" x-bind:disabled="submitting || {{ $preview['valid_rows'] === 0 ? 'true' : 'false' }}">
                    <span x-show="!submitting">{{ __('Import') }} {{ $preview['valid_rows'] }} {{ __('Records') }}</span>
                    <span x-show="submitting" x-cloak>{{ __('Importing…') }}</span>
                </x-shell.button>
            </form>
        </div>
    </div>
</x-app-layout>

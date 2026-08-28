<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Upload Duty List" :subtitle="'Session: '.$dutySession->date->format('d M Y')" :back-url="route('sessions.show', $dutySession)" />
    </x-slot>

    <div class="space-y-5">
        @if ($errors->has('file'))
            <div class="rounded-2xl bg-red-50 p-4 text-sm text-red-700">{{ $errors->first('file') }}</div>
        @endif

        <form method="POST" action="{{ route('sessions.imports.store', $dutySession) }}" enctype="multipart/form-data" class="space-y-5" x-data="{ fileName: null }">
            @csrf

            <label for="file" class="flex cursor-pointer flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-500">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9A5.5 5.5 0 0 1 17 8a4.5 4.5 0 0 1 .5 9H7Zm5-4v6m0-6-2.5 2.5M12 12l2.5 2.5" />
                    </svg>
                </span>
                <span class="text-sm text-slate-500" x-text="fileName ?? 'Drag & Drop Excel / CSV file here'"></span>
                <span class="text-xs text-slate-400">{{ __('or') }}</span>
                <span class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">{{ __('Choose File') }}</span>
                <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv" required class="hidden"
                       @change="fileName = $event.target.files[0]?.name" />
                <span class="text-xs text-slate-400">{{ __('Supported formats: .xlsx, .xls, .csv') }}</span>
            </label>

            <x-shell.button tone="primary" type="submit">{{ __('Upload & Preview') }}</x-shell.button>
        </form>

        <x-shell.card>
            <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Tips') }}</h3>
            <ul class="space-y-1.5 text-sm text-slate-500">
                <li>&bull; {{ __('Required columns: ITS ID, FullName, Venue Name.') }}</li>
                <li>&bull; {{ __('First row must contain column headers.') }}</li>
                <li>&bull; {{ __('Max file size 10MB.') }}</li>
                <li>&bull; {{ __('The same session may receive multiple files — duplicates are detected automatically.') }}</li>
            </ul>
        </x-shell.card>
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Create New Session" :back-url="route('sessions.index')" />
    </x-slot>

    <div class="space-y-5">
        @if ($errors->any())
            <div class="rounded-2xl bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-shell.card>
            <form method="POST" action="{{ route('sessions.store') }}" class="space-y-5">
                @csrf

                <div>
                    <x-input-label for="date" :value="__('Session Date')" />
                    <x-text-input id="date" name="date" type="date" class="mt-1 block w-full" :value="old('date', now()->format('Y-m-d'))" required />
                    <x-input-error :messages="$errors->get('date')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="name" :value="__('Session Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" placeholder="e.g. Daily Khidmatguzar Attendance" required autofocus />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="h_year" :value="__('HYear (optional)')" />
                        <x-text-input id="h_year" name="h_year" type="text" class="mt-1 block w-full" :value="old('h_year')" />
                    </div>
                    <div>
                        <x-input-label for="miqaat" :value="__('Miqaat (optional)')" />
                        <x-text-input id="miqaat" name="miqaat" type="text" class="mt-1 block w-full" :value="old('miqaat')" />
                    </div>
                </div>

                <div>
                    <x-input-label for="remarks" :value="__('Remarks (optional)')" />
                    <textarea id="remarks" name="remarks" rows="3" maxlength="200"
                        class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        placeholder="All Departments — Daily Duty List">{{ old('remarks') }}</textarea>
                </div>

                <x-shell.button tone="primary" type="submit">{{ __('Create Session') }}</x-shell.button>
            </form>
        </x-shell.card>

        <x-shell.info-card>
            {{ __('A new session will allow you to upload duty lists and manage attendance for the day.') }}
        </x-shell.info-card>
    </div>
</x-app-layout>

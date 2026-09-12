<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Venues" :back-url="route('masters.index')" />
    </x-slot>

    <div class="space-y-5">
        @if (session('status'))
            <div class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        <x-shell.card>
            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Add Venue') }}</h3>
            <form method="POST" action="{{ route('masters.venues.store') }}" class="grid grid-cols-2 gap-3">
                @csrf
                <div class="col-span-2">
                    <x-input-label for="name" :value="__('Venue Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="city" :value="__('City (optional)')" />
                    <x-text-input id="city" name="city" type="text" class="mt-1 block w-full" :value="old('city')" />
                </div>
                <div>
                    <x-input-label for="area" :value="__('Area / Location (optional)')" />
                    <x-text-input id="area" name="area" type="text" class="mt-1 block w-full" :value="old('area')" />
                </div>
                <div class="col-span-2">
                    <x-input-label for="address" :value="__('Full Address (optional)')" />
                    <textarea id="address" name="address" rows="2" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('address') }}</textarea>
                </div>
                <div class="col-span-2">
                    <x-shell.button tone="primary" type="submit">{{ __('Add Venue') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        @if ($venues->isEmpty())
            <x-shell.empty-state title="{{ __('No venues yet') }}" />
        @else
            <div class="space-y-2 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 xl:grid-cols-3">
                @foreach ($venues as $venue)
                    <div class="kg-card-hover flex items-start justify-between gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $venue->name }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $venue->city ?: __('No city set') }}@if($venue->area) &middot; {{ $venue->area }} @endif</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-shell.badge :tone="$venue->active ? 'green' : 'gray'" dot>{{ $venue->active ? __('Active') : __('Inactive') }}</x-shell.badge>
                            <a href="{{ route('masters.venues.edit', $venue) }}" class="kg-tap flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-50 hover:text-slate-700" aria-label="{{ __('Edit') }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232 18.768 8.768M4 20h4l10.586-10.586a2 2 0 0 0 0-2.828l-1.172-1.172a2 2 0 0 0-2.828 0L4 16v4Z" /></svg>
                            </a>
                            <form method="POST" action="{{ route('masters.venues.toggle', $venue) }}">
                                @csrf
                                <button type="submit" class="kg-tap flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-50 hover:text-slate-700" aria-label="{{ $venue->active ? __('Deactivate') : __('Activate') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 4.626c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>

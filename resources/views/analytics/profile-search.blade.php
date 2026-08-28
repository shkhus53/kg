<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Khidmatguzar Directory" :back-url="route('dashboard')" />
    </x-slot>

    <div class="space-y-4">
        <x-shell.card>
            <form method="GET" action="{{ route('analytics.profile-search') }}" class="space-y-3">
                <x-text-input name="q" type="text" class="block w-full" :value="$query" placeholder="{{ __('Search by ITS Number or Full Name') }}" autofocus />

                <div class="grid grid-cols-2 gap-3">
                    <select name="department_id" class="rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('Any Department') }}</option>
                        @foreach ($departmentOptions as $dept)
                            <option value="{{ $dept->id }}" @selected((string) $departmentId === (string) $dept->id)>{{ $dept->name }}</option>
                        @endforeach
                    </select>
                    <x-text-input name="jamaat" type="text" class="block w-full" :value="$jamaat" placeholder="{{ __('Jamaat') }}" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="from" :value="__('Served From')" />
                        <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$from" />
                    </div>
                    <div>
                        <x-input-label for="to" :value="__('Served To')" />
                        <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$to" />
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="has_served" value="1" @checked($hasServed) class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    {{ __('Has at least one scheduled duty') }}
                </label>

                <x-shell.button tone="primary" type="submit">{{ __('Search') }}</x-shell.button>
            </form>
        </x-shell.card>

        @if ($matches->isEmpty())
            <x-shell.card class="text-center text-slate-400">{{ __('No Khidmatguzar found for this search.') }}</x-shell.card>
        @else
            <div class="space-y-2">
                @foreach ($matches as $person)
                    <a href="{{ route('analytics.profile', $person) }}" class="flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $person->full_name }}</p>
                            <p class="truncate text-xs text-slate-400">
                                {{ __('ITS') }}: {{ $person->its_id }}
                                @if ($person->jamaat) &middot; {{ $person->jamaat }} @endif
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $person->total_duties }} {{ __('duties') }} &middot;
                                <span class="text-emerald-600">{{ $person->present_count }} {{ __('present') }}</span> &middot;
                                <span class="text-red-500">{{ $person->absent_count }} {{ __('absent') }}</span>
                                @if ($person->extra_count > 0)
                                    &middot; <span class="text-violet-600">{{ $person->extra_count }} {{ __('extra') }}</span>
                                @endif
                                @if ($person->rate !== null)
                                    &middot; {{ $person->rate }}%
                                @endif
                            </p>
                        </div>
                        <svg class="h-4 w-4 shrink-0 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
                    </a>
                @endforeach
            </div>

            <div>{{ $matches->onEachSide(1)->links() }}</div>
        @endif
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Create Event Session" :back-url="route('sessions.index')" />
    </x-slot>

    <div class="space-y-5 lg:mx-auto lg:max-w-2xl">
        @if ($errors->any())
            <div class="rounded-2xl bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-shell.steps :steps="['Event', 'Date & Venue', 'Duty List']" :current="$selectedEventId ? 'Date & Venue' : 'Event'" />

        {{-- Step 1: Miqaat -> Event. A plain GET reload, no fetch/AJAX —
             each select auto-submits (existing app convention, e.g.
             attendance/list.blade.php's department filter). --}}
        <x-shell.card>
            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('1. Event') }}</h3>
            @if ($miqaats->isEmpty())
                <x-shell.empty-state title="{{ __('No active Miqaats') }}" description="{{ __('Add one under Master Data before creating an event session.') }}" />
            @else
                <form method="GET" action="{{ route('sessions.create') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="miqaat_id" :value="__('Miqaat')" />
                        <select id="miqaat_id" name="miqaat_id" required onchange="this.form.submit()"
                                class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">{{ __('Choose Miqaat…') }}</option>
                            @foreach ($miqaats as $miqaat)
                                <option value="{{ $miqaat->id }}" @selected((string) $selectedMiqaatId === (string) $miqaat->id)>{{ $miqaat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="event_id" :value="__('Event')" />
                        <select id="event_id" name="event_id" required @disabled(! $selectedMiqaatId) onchange="this.form.submit()"
                                class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400">
                            <option value="">{{ $selectedMiqaatId ? __('Choose Event…') : __('Choose a Miqaat first') }}</option>
                            @foreach ($events as $event)
                                <option value="{{ $event->id }}" @selected((string) $selectedEventId === (string) $event->id)>
                                    {{ $event->name }}@if($event->family) &middot; {{ $event->family }} @endif
                                </option>
                            @endforeach
                        </select>
                        @if ($selectedMiqaatId && $events->isEmpty())
                            <p class="mt-1 text-xs text-orange-500">{{ __('No active events under this Miqaat yet.') }}</p>
                        @endif
                    </div>
                    <noscript><button type="submit" class="col-span-2 rounded-xl bg-slate-100 py-2 text-xs font-semibold text-slate-700">{{ __('Continue') }}</button></noscript>
                </form>
            @endif
        </x-shell.card>

        @if ($selectedEventId)
            @php $selectedEvent = $events->firstWhere('id', (int) $selectedEventId); @endphp

            @include('sessions.partials.forecast', ['forecast' => $forecast])

            {{-- Step 2: Date & Venue, then create. Independent POST form —
                 changing Event above simply re-renders this step fresh. --}}
            <x-shell.card>
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('2. Date & Venue') }}</h3>
                <form method="POST" action="{{ route('sessions.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="miqaat_id" value="{{ $selectedMiqaatId }}">
                    <input type="hidden" name="event_id" value="{{ $selectedEventId }}">

                    <div class="rounded-xl bg-slate-50 p-3 text-sm">
                        <p class="font-medium text-slate-900">{{ $selectedEvent?->name }}</p>
                        <p class="text-xs text-slate-400">{{ $miqaats->firstWhere('id', (int) $selectedMiqaatId)?->name }}</p>
                    </div>

                    <div>
                        <x-input-label for="date" :value="__('Event Date')" />
                        <x-text-input id="date" name="date" type="date" class="mt-1 block w-full" :value="old('date', $selectedDate)" required />
                        <x-input-error :messages="$errors->get('date')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="venue_id" :value="__('Venue')" />
                        @if ($venues->isEmpty())
                            <p class="mt-1 text-xs text-orange-500">{{ __('No active venues — add one under Master Data first.') }}</p>
                        @else
                            <select id="venue_id" name="venue_id" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">{{ __('Choose Venue…') }}</option>
                                @foreach ($venues as $venue)
                                    <option value="{{ $venue->id }}" @selected((string) old('venue_id', $selectedVenueId) === (string) $venue->id)>
                                        {{ $venue->name }}@if($venue->city) &middot; {{ $venue->city }} @endif
                                    </option>
                                @endforeach
                            </select>
                        @endif
                        <x-input-error :messages="$errors->get('venue_id')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="h_year" :value="__('HYear (optional)')" />
                            <x-text-input id="h_year" name="h_year" type="text" class="mt-1 block w-full" :value="old('h_year')" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="remarks" :value="__('Remarks (optional)')" />
                        <textarea id="remarks" name="remarks" rows="2" maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('remarks') }}</textarea>
                    </div>

                    <x-shell.button tone="primary" type="submit" :disabled="$venues->isEmpty()">{{ __('Create Session') }}</x-shell.button>
                </form>
            </x-shell.card>
        @endif

        <x-shell.info-card>
            {{ __('A new session will allow you to upload duty lists and manage attendance for the day.') }}
        </x-shell.info-card>
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Khidmatguzar Directory" :back-url="route('dashboard')" />
    </x-slot>

    <div class="space-y-4">
        @if ($departmentId || $status || $sessionId)
            <x-shell.breadcrumb :items="[
                ['label' => 'Overview', 'url' => route('analytics.overview')],
                ...($departmentId ? [['label' => $departmentName ?? 'Department', 'url' => route('analytics.profile-search', ['department_id' => $departmentId])]] : []),
                ...($status ? [['label' => ucfirst($status), 'url' => null]] : []),
                ['label' => 'Members', 'url' => null],
            ]" />
        @endif

        <x-shell.card class="lg:sticky lg:top-4 lg:z-10">
            <form method="GET" action="{{ route('analytics.profile-search') }}" class="space-y-3 lg:grid lg:grid-cols-4 lg:items-end lg:gap-3 lg:space-y-0">
                <input type="hidden" name="session_id" value="{{ $sessionId }}">
                <x-text-input name="q" type="text" class="block w-full lg:col-span-2" :value="$query" placeholder="{{ __('Search by ITS Number or Full Name') }}" autofocus />

                <select name="department_id" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('Any Department') }}</option>
                    @foreach ($departmentOptions as $dept)
                        <option value="{{ $dept->id }}" @selected((string) $departmentId === (string) $dept->id)>{{ $dept->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('Any Status') }}</option>
                    @foreach (['present' => 'Present', 'absent' => 'Absent', 'pending' => 'Pending'] as $val => $label)
                        <option value="{{ $val }}" @selected($status === $val)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="gender" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="all" @selected($gender === 'all')>{{ __('All Genders') }}</option>
                    <option value="Male" @selected($gender === 'Male')>{{ __('Male') }}</option>
                    <option value="Female" @selected($gender === 'Female')>{{ __('Female') }}</option>
                    <option value="Unknown" @selected($gender === 'Unknown')>{{ __('Unknown') }}</option>
                </select>

                <x-text-input name="jamaat" type="text" class="block w-full" :value="$jamaat" placeholder="{{ __('Jamaat') }}" />

                <div>
                    <x-input-label for="from" :value="__('Served From')" />
                    <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$from" />
                </div>
                <div>
                    <x-input-label for="to" :value="__('Served To')" />
                    <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$to" />
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600 lg:col-span-2">
                    <input type="checkbox" name="has_served" value="1" @checked($hasServed) class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    {{ __('Has at least one scheduled duty') }}
                </label>

                <x-shell.button tone="primary" type="submit" class="lg:col-span-2">{{ __('Search') }}</x-shell.button>
            </form>
        </x-shell.card>

        <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <a href="{{ request()->fullUrlWithQuery(['gender' => 'all', 'page' => null]) }}" class="rounded-2xl border p-3 text-center {{ $gender === 'all' ? 'border-navy-900 bg-navy-900/5' : 'border-slate-100 bg-white' }}">
                <p class="text-lg font-semibold text-slate-900">{{ number_format($totalCount) }}</p>
                <p class="text-xs text-slate-400">{{ __('Total') }}</p>
            </a>
            <a href="{{ request()->fullUrlWithQuery(['gender' => 'Male', 'page' => null]) }}" class="rounded-2xl border p-3 text-center {{ $gender === 'Male' ? 'border-navy-900 bg-navy-900/5' : 'border-slate-100 bg-white' }}">
                <p class="text-lg font-semibold text-blue-600">{{ number_format($genderCounts['Male']) }}</p>
                <p class="text-xs text-slate-400">{{ __('Male') }}</p>
            </a>
            <a href="{{ request()->fullUrlWithQuery(['gender' => 'Female', 'page' => null]) }}" class="rounded-2xl border p-3 text-center {{ $gender === 'Female' ? 'border-navy-900 bg-navy-900/5' : 'border-slate-100 bg-white' }}">
                <p class="text-lg font-semibold text-violet-600">{{ number_format($genderCounts['Female']) }}</p>
                <p class="text-xs text-slate-400">{{ __('Female') }}</p>
            </a>
            <a href="{{ request()->fullUrlWithQuery(['gender' => 'Unknown', 'page' => null]) }}" class="rounded-2xl border p-3 text-center {{ $gender === 'Unknown' ? 'border-navy-900 bg-navy-900/5' : 'border-slate-100 bg-white' }}">
                <p class="text-lg font-semibold text-slate-500">{{ number_format($genderCounts['Unknown']) }}</p>
                <p class="text-xs text-slate-400">{{ __('Unknown') }}</p>
            </a>
        </div>

        @if ($matches->isEmpty())
            <x-shell.empty-state title="{{ __('No Khidmatguzar found') }}" description="{{ __('Try broadening the search or clearing a filter.') }}">
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm-8 8a6 6 0 0 0-6 6h20a6 6 0 0 0-6-6H8Z" /></svg>
                </x-slot:icon>
            </x-shell.empty-state>
        @else
            <div class="space-y-2 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 xl:grid-cols-3">
                @foreach ($matches as $person)
                    <a href="{{ route('analytics.profile', $person) }}" class="kg-card-hover kg-tap flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
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

            <p class="text-center text-xs text-slate-400">
                {{ __('Showing :from–:to of :total', ['from' => $matches->firstItem(), 'to' => $matches->lastItem(), 'total' => number_format($matches->total())]) }}
            </p>
            <div>{{ $matches->onEachSide(1)->links('pagination.tailwind-no-summary') }}</div>
        @endif
    </div>
</x-app-layout>

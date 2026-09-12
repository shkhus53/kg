<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="User Management" :back-url="route('dashboard')">
            <x-slot:actions>
                <a href="{{ route('users.create') }}" aria-label="{{ __('Add User') }}" class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                </a>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-5">
        @if (session('status'))
            <div class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        <x-shell.card>
            <form method="GET" action="{{ route('users.index') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-[2fr_1fr_1fr_auto] lg:items-end">
                <div class="col-span-2 lg:col-span-1">
                    <x-input-label for="search" :value="__('Search (name or ITS)')" />
                    <x-text-input id="search" name="search" type="text" class="mt-1 block w-full" :value="$search" placeholder="{{ __('e.g. Husain or 30123456') }}" />
                </div>
                <div>
                    <x-input-label for="role" :value="__('Role')" />
                    <select id="role" name="role" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('Any role') }}</option>
                        @foreach (\App\Models\User::ROLES as $r)
                            <option value="{{ $r }}" @selected($role === $r)>{{ ucfirst($r) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="status" :value="__('Status')" />
                    <select id="status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('Any status') }}</option>
                        <option value="active" @selected($status === 'active')>{{ __('Active') }}</option>
                        <option value="inactive" @selected($status === 'inactive')>{{ __('Inactive') }}</option>
                    </select>
                </div>
                <div class="col-span-2 flex gap-2 lg:col-span-1">
                    <x-shell.button tone="primary" type="submit">{{ __('Apply') }}</x-shell.button>
                    @if ($search !== '' || $role || $status)
                        <x-shell.button tone="outline" href="{{ route('users.index') }}">{{ __('Clear') }}</x-shell.button>
                    @endif
                </div>
            </form>
        </x-shell.card>

        @if ($users->isEmpty())
            <x-shell.empty-state title="{{ __('No users match these filters') }}" description="{{ __('Try clearing search, role, or status filters.') }}">
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm-8 8a6 6 0 0 0-6 6h20a6 6 0 0 0-6-6H8Z" /></svg>
                </x-slot:icon>
            </x-shell.empty-state>
        @else
            <div class="space-y-2">
                @foreach ($users as $u)
                    <a href="{{ route('users.edit', $u) }}" class="kg-card-hover kg-tap flex items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $u->name }}</p>
                            <p class="truncate text-xs text-slate-400">
                                {{ __('ITS') }} {{ $u->its_number }} &middot; {{ ucfirst($u->role) }} &middot; {{ __('created') }} {{ $u->created_at->toIst()->format('d M Y') }}
                            </p>
                        </div>
                        <x-shell.badge :tone="$u->is_active ? 'green' : 'gray'" dot>{{ $u->is_active ? __('Active') : __('Inactive') }}</x-shell.badge>
                    </a>
                @endforeach
            </div>

            <div>{{ $users->links() }}</div>
        @endif
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Edit User" :subtitle="$editedUser->name" :back-url="route('users.index')" />
    </x-slot>

    <div class="space-y-5 lg:mx-auto lg:max-w-xl">
        @if (session('status'))
            <div class="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        @if (auth()->id() === $editedUser->id)
            <x-shell.info-card>{{ __('This is your own account — you cannot deactivate yourself here.') }}</x-shell.info-card>
        @endif

        <x-shell.card>
            <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ __('Details') }}</h3>
            <form method="POST" action="{{ route('users.update', $editedUser) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <x-input-label for="name" :value="__('Full Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $editedUser->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="its_number" :value="__('ITS Number')" />
                    <x-text-input id="its_number" name="its_number" type="text" inputmode="numeric" pattern="\d{8}" maxlength="8" class="mt-1 block w-full" :value="old('its_number', $editedUser->its_number)" required />
                    <x-input-error :messages="$errors->get('its_number')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="role" :value="__('Role')" />
                    <select id="role" name="role" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach (\App\Models\User::ROLES as $r)
                            <option value="{{ $r }}" @selected(old('role', $editedUser->role) === $r)>{{ ucfirst($r) }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="is_active" :value="__('Status')" />
                    <select id="is_active" name="is_active" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="1" @selected(old('is_active', $editedUser->is_active ? '1' : '0') === '1')>{{ __('Active') }}</option>
                        <option value="0" @selected(old('is_active', $editedUser->is_active ? '1' : '0') === '0')>{{ __('Inactive') }}</option>
                    </select>
                    <x-input-error :messages="$errors->get('is_active')" class="mt-2" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <x-shell.button tone="outline" href="{{ route('users.index') }}">{{ __('Cancel') }}</x-shell.button>
                    <x-shell.button tone="primary" type="submit">{{ __('Save Changes') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        <x-shell.card x-data="{ show: false }">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">{{ __('Password') }}</h3>
                <button type="button" @click="show = !show" class="text-xs font-semibold text-blue-600">
                    <span x-show="!show">{{ __('Set New Password') }}</span>
                    <span x-show="show" x-cloak>{{ __('Cancel') }}</span>
                </button>
            </div>
            <p class="mt-1 text-xs text-slate-400">{{ __('The existing password is never shown. Setting a new one replaces it immediately.') }}</p>

            <form x-show="show" x-cloak x-transition method="POST" action="{{ route('users.password.update', $editedUser) }}" class="mt-3 space-y-3">
                @csrf
                @method('PUT')
                <div>
                    <x-input-label for="new_password" :value="__('New Password')" />
                    <x-text-input id="new_password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="new_password_confirmation" :value="__('Confirm New Password')" />
                    <x-text-input id="new_password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                </div>
                <x-shell.button tone="warning" type="submit">{{ __('Update Password') }}</x-shell.button>
            </form>
        </x-shell.card>

        @if ($editedUser->isAdmin())
            <x-shell.info-card>{{ __('This user is an Admin — Admin always has full unrestricted access. Permission overrides cannot be set and would have no effect.') }}</x-shell.info-card>
        @else
            <x-shell.card>
                <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Permissions') }}</h3>
                <p class="mb-4 text-xs text-slate-400">{{ __('Role gives the default access below. An override (Allow/Deny) always takes precedence over the role default for this one user — set it back to "Inherit from role" to remove the override.') }}</p>

                <form method="POST" action="{{ route('users.permissions.update', $editedUser) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    @foreach ($permissionGroups as $groupLabel => $permissions)
                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __($groupLabel) }}</h4>
                            <div class="space-y-2">
                                @foreach ($permissions as $permission)
                                    @php
                                        $isRoleDefault = in_array($permission, $roleDefaults, true);
                                        $currentOverride = $overrides[$permission] ?? 'inherit';
                                        $effective = $effectiveMap[$permission]['effective'];
                                    @endphp
                                    <div class="flex flex-col gap-2 rounded-xl border border-slate-100 p-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm text-slate-800">{{ \App\Support\PermissionRegistry::label($permission) }}</p>
                                            <p class="text-[11px] text-slate-400">
                                                {{ $isRoleDefault ? __('Included by role default') : __('Not included by role default') }}
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-2">
                                            <x-shell.badge :tone="$effective ? 'green' : 'gray'" dot>{{ $effective ? __('Effective: Allowed') : __('Effective: Not Allowed') }}</x-shell.badge>
                                            <select name="overrides[{{ $permission }}]" class="rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="inherit" @selected($currentOverride === 'inherit')>{{ __('Inherit from role') }}</option>
                                                <option value="allow" @selected($currentOverride === 'allow')>{{ __('Allow') }}</option>
                                                <option value="deny" @selected($currentOverride === 'deny')>{{ __('Deny') }}</option>
                                            </select>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <x-input-error :messages="$errors->get('permissions')" class="mt-2" />
                    <x-shell.button tone="primary" type="submit">{{ __('Save Permissions') }}</x-shell.button>
                </form>
            </x-shell.card>
        @endif

        <div>
            <h2 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Change History') }}</h2>
            @php
                $history = app(\App\Services\MasterDataAuditService::class)->history('user', $editedUser->id)
                    ->concat(app(\App\Services\MasterDataAuditService::class)->history('user_permission', $editedUser->id))
                    ->sortByDesc('changed_at')->values();
            @endphp
            @if ($history->isEmpty())
                <x-shell.empty-state title="{{ __('No changes recorded yet') }}" />
            @else
                <div class="space-y-2">
                    @foreach ($history as $entry)
                        <div class="rounded-xl border border-slate-100 bg-white p-3 text-sm">
                            <p class="font-medium text-slate-900">
                                @if ($entry->field === 'status')
                                    {{ str($entry->new_value)->replace('_', ' ')->ucfirst() }}
                                @else
                                    {{ ucfirst(str_replace('_', ' ', $entry->field)) }}: <span class="text-slate-400 line-through">{{ $entry->old_value ?: '—' }}</span> → <span class="font-semibold text-blue-700">{{ $entry->new_value }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-slate-400">{{ $entry->changedBy->name }} &middot; {{ $entry->changed_at->toIst()->format('d M Y H:i') }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

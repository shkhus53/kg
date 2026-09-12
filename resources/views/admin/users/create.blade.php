<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Add User" :back-url="route('users.index')" />
    </x-slot>

    <div class="space-y-5 lg:mx-auto lg:max-w-xl">
        <x-shell.card>
            <form method="POST" action="{{ route('users.store') }}" class="space-y-4">
                @csrf
                <div>
                    <x-input-label for="name" :value="__('Full Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="its_number" :value="__('ITS Number')" />
                    <x-text-input id="its_number" name="its_number" type="text" inputmode="numeric" pattern="\d{8}" maxlength="8" class="mt-1 block w-full" :value="old('its_number')" required />
                    <x-input-error :messages="$errors->get('its_number')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="role" :value="__('Role')" />
                    <select id="role" name="role" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach (\App\Models\User::ROLES as $r)
                            <option value="{{ $r }}" @selected(old('role') === $r)>{{ ucfirst($r) }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="is_active" :value="__('Status')" />
                    <select id="is_active" name="is_active" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="1" @selected(old('is_active', '1') === '1')>{{ __('Active') }}</option>
                        <option value="0" @selected(old('is_active') === '0')>{{ __('Inactive') }}</option>
                    </select>
                    <x-input-error :messages="$errors->get('is_active')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="password" :value="__('Password')" />
                    <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <x-shell.button tone="outline" href="{{ route('users.index') }}">{{ __('Cancel') }}</x-shell.button>
                    <x-shell.button tone="primary" type="submit">{{ __('Create User') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>
    </div>
</x-app-layout>

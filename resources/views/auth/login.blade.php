<x-guest-layout>
    <h2 class="mb-6 text-center text-lg font-semibold text-slate-900">{{ __('Log in') }}</h2>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="its_number" :value="__('ITS Number')" />
            <x-text-input id="its_number" class="block w-full text-center tracking-widest" type="text" inputmode="numeric" pattern="\d{8}" maxlength="8" name="its_number" :value="old('its_number')" required autofocus autocomplete="username" placeholder="8 digit ITS number" />
            <x-input-error :messages="$errors->get('its_number')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="flex items-center gap-2 text-sm text-slate-600">
            <input id="remember_me" type="checkbox" name="remember" class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500">
            {{ __('Remember me') }}
        </label>

        <x-shell.button tone="primary" type="submit">{{ __('Log in') }}</x-shell.button>
    </form>
</x-guest-layout>

<x-guest-layout>
    <h2 class="mb-6 text-center text-lg font-semibold text-slate-900">{{ __('Log in') }}</h2>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-4" data-keyboard-aware-form x-data="{ submitting: false }" @submit="submitting = true">
        @csrf

        <div>
            <x-input-label for="its_number" :value="__('ITS Number')" />
            <x-text-input id="its_number" class="block w-full text-center tracking-widest" type="text" inputmode="numeric" pattern="\d{8}" maxlength="8" name="its_number" :value="old('its_number')" required autofocus autocomplete="username" enterkeyhint="next" placeholder="8 digit ITS number" />
            <x-input-error :messages="$errors->get('its_number')" class="mt-2" />
        </div>

        <div x-data="{ show: false }">
            <x-input-label for="password" :value="__('Password')" />
            <div class="relative">
                <x-text-input id="password" class="block w-full pr-11" type="password" x-bind:type="show ? 'text' : 'password'" name="password" required autocomplete="current-password" enterkeyhint="go" />
                <button type="button" @click="show = !show"
                        class="kg-tap absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 hover:text-slate-600"
                        :aria-label="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'" :aria-pressed="show.toString()">
                    <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    <svg x-show="show" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.774 3.162 10.066 7.5a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                </button>
            </div>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="flex items-center gap-2 text-sm text-slate-600">
            <input id="remember_me" type="checkbox" name="remember" class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500">
            {{ __('Remember me') }}
        </label>

        <x-shell.button tone="primary" type="submit" x-bind:disabled="submitting">
            <span x-show="!submitting">{{ __('Log in') }}</span>
            <span x-show="submitting" x-cloak>{{ __('Logging in…') }}</span>
        </x-shell.button>
    </form>
</x-guest-layout>

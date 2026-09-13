<x-guest-layout>
    <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-[1.75rem]">{{ __('Welcome Back') }}</h2>
    <p class="mt-1.5 text-sm text-slate-500">{{ __('Sign in to continue to Khidmatguzar Attendance') }}</p>

    @if (session('status'))
        <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-sm font-medium text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-7 space-y-4" data-keyboard-aware-form x-data="{ submitting: false }" @submit="submitting = true">
        @csrf

        <div>
            <label for="its_number" class="sr-only">{{ __('ITS Number') }}</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-11 items-center justify-center text-slate-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm-8 8a6 6 0 0 0-6 6h20a6 6 0 0 0-6-6H8Z" /></svg>
                </span>
                <input id="its_number" class="kg-glass-input block w-full rounded-xl py-3.5 pl-11 pr-4 text-base shadow-sm"
                       type="text" inputmode="numeric" pattern="\d{8}" maxlength="8" name="its_number" value="{{ old('its_number') }}"
                       required autofocus autocomplete="username" enterkeyhint="next" placeholder="{{ __('Enter your 8 digit ITS number') }}">
            </div>
            @error('its_number')
                <p class="mt-2 text-sm font-medium text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div x-data="{ show: false }">
            <label for="password" class="sr-only">{{ __('Password') }}</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-11 items-center justify-center text-slate-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2Zm10-10V7a4 4 0 1 0-8 0v4h8Z" /></svg>
                </span>
                <input id="password" class="kg-glass-input block w-full rounded-xl py-3.5 pl-11 pr-12 text-base shadow-sm"
                       type="password" x-bind:type="show ? 'text' : 'password'" name="password"
                       required autocomplete="current-password" enterkeyhint="go" placeholder="{{ __('Enter your password') }}">
                <button type="button" @click="show = !show"
                        class="kg-tap absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 hover:text-indigo-600"
                        :aria-label="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'" :aria-pressed="show.toString()">
                    <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    <svg x-show="show" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.774 3.162 10.066 7.5a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                </button>
            </div>
            @error('password')
                <p class="mt-2 text-sm font-medium text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between pt-1">
            <label for="remember_me" class="flex items-center gap-2 text-sm text-slate-600">
                <input id="remember_me" type="checkbox" name="remember" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                {{ __('Remember me') }}
            </label>
        </div>

        <button type="submit" x-bind:disabled="submitting"
                class="kg-tap flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-blue-600 px-4 py-3.5 text-base font-semibold text-white shadow-[0_16px_35px_-10px_rgba(79,70,229,0.55)] transition hover:from-indigo-500 hover:to-blue-500 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">
            <span x-show="!submitting" class="inline-flex items-center gap-2">
                {{ __('Log in') }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
            </span>
            <span x-show="submitting" x-cloak class="inline-flex items-center gap-2">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path></svg>
                {{ __('Logging in…') }}
            </span>
        </button>
    </form>
</x-guest-layout>

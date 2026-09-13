<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{--
            interactive-widget=resizes-content: modern Android Chrome's
            default is resizes-visual, which shrinks ONLY visualViewport
            when the on-screen keyboard opens — window.innerHeight and every
            100vh/min-h-screen box stay at their pre-keyboard size. Since
            this guest shell centers short content in a min-height:100vh
            flex box, that box never actually shrinks or overflows, so
            there is nothing for auth-viewport.js's overflow toggle or
            scrollIntoView to act on: the keyboard visually covers the
            lower fields while the (unchanged) layout box reports plenty
            of room. resizes-content makes Chrome/WebView shrink the real
            layout viewport (and therefore 100vh) together with the
            keyboard, so the flex box recomputes to the smaller height and
            genuinely overflows/scrolls when content no longer fits — the
            platform mechanism this class of bug is meant to be solved
            with, not a JS heuristic. auth-viewport.js is kept as a
            fallback for older WebViews that predate this meta directive
            (Chromium < 108).
        --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#eef2ff">
        <link rel="manifest" href="/manifest.json">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen font-sans text-slate-900 antialiased">
        {{--
            .kg-guest-shell is load-bearing for the keyboard-open CSS in
            app.css (.kg-kbd-open .kg-guest-shell) and for auth-viewport.js's
            keyboard-aware behavior — kept exactly as before.
        --}}
        <div class="kg-guest-shell kg-guest-bg relative flex min-h-screen flex-col items-center justify-center overflow-hidden px-4 py-10 lg:items-stretch lg:justify-stretch lg:p-0">
            {{-- Light luminous atmosphere: one large flowing arc + three soft blurred glows. Static, no motion, no images. --}}
            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="kg-flow-arc hidden lg:block"></div>
                <div class="kg-glow kg-glow-a"></div>
                <div class="kg-glow kg-glow-b"></div>
                <div class="kg-glow kg-glow-c"></div>
            </div>

            <div class="relative z-10 grid w-full max-w-6xl grid-cols-1 items-center gap-10 lg:min-h-screen lg:grid-cols-[1.05fr_1fr] lg:gap-16 lg:px-16 xl:px-24">
                {{-- Brand panel --}}
                <div class="flex flex-col items-center text-center lg:items-start lg:text-left">
                    <img src="{{ asset('images/kg_icon.png') }}" alt="{{ config('app.name') }}"
                         class="h-[86px] w-[86px] shrink-0 rounded-3xl object-contain shadow-[0_20px_50px_-12px_rgba(79,70,229,0.35)] lg:h-[132px] lg:w-[132px]">

                    <h1 class="mt-6 text-3xl font-extrabold leading-tight tracking-tight text-slate-900 lg:mt-8 lg:text-5xl">
                        {{ __('Khidmatguzar') }}<br class="hidden lg:block">
                        <span class="lg:inline"> {{ __('Attendance') }}</span>
                    </h1>
                    <p class="mt-3 text-base font-medium text-indigo-600 lg:mt-4 lg:text-lg">
                        {{ __('Mark Today. Build Tomorrow.') }}
                    </p>

                    <div class="mt-12 hidden w-full max-w-none grid-cols-1 gap-5 lg:grid">
                        @foreach ([
                            ['icon' => 'M13 10V3L4 14h7v7l9-11h-7Z', 'title' => 'Simple Attendance', 'desc' => 'Quick and effortless'],
                            ['icon' => 'M9 17V9m3 8V5m3 12v-4M5 21h14a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1Z', 'title' => 'Useful Insights', 'desc' => 'Make better decisions'],
                            ['icon' => 'M12 3 4 6v6c0 4.5 3.4 8.7 8 9 4.6-.3 8-4.5 8-9V6l-8-3Z', 'title' => 'Secure & Reliable', 'desc' => 'Your data is protected'],
                        ] as $feature)
                            <div class="flex flex-col items-center gap-2 text-center sm:flex-row sm:items-center sm:gap-3 sm:text-left lg:flex-row lg:text-left">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white/70 text-indigo-600 shadow-sm ring-1 ring-indigo-100 backdrop-blur">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $feature['icon'] }}" /></svg>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-slate-800">{{ __($feature['title']) }}</span>
                                    <span class="block text-xs text-slate-500">{{ __($feature['desc']) }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Premium white glass login card --}}
                <div class="w-full max-w-md justify-self-center lg:max-w-[440px] lg:justify-self-end">
                    <div class="kg-glass-card rounded-[2rem] p-7 sm:p-10">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>

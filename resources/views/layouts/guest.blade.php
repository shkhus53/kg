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
        <meta name="theme-color" content="#f1f5fd">
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

            Composition follows the approved reference: branding + card sit
            together toward the left/center as two independent floating
            elements (fixed widths, flex-start, no stretch), leaving the
            right portion of the viewport as genuine intentional whitespace
            rather than redistributing everything evenly. Not "fixed" —
            not on mobile, where the same two blocks simply stack full-width.
        --}}
        <div class="kg-guest-shell kg-guest-bg relative flex min-h-screen flex-col items-center justify-center overflow-hidden px-5 py-10 lg:items-center lg:justify-center lg:px-0">
            {{-- Soft luminous atmosphere: one large translucent orb + gentle glows, exactly like the reference's quiet light-through-glass feel. --}}
            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="kg-orb hidden lg:block"></div>
                <div class="kg-glow kg-glow-a"></div>
                <div class="kg-glow kg-glow-b"></div>
            </div>

            <div class="relative z-10 flex w-full max-w-[1400px] flex-col items-center gap-14 px-2 lg:flex-row lg:items-center lg:justify-start lg:gap-20 lg:px-24">
                {{-- Brand panel — fixed width on desktop, not stretched. --}}
                <div class="flex w-full max-w-md flex-col items-center text-center lg:w-[400px] lg:shrink-0 lg:items-start lg:text-left">
                    <img src="{{ asset('images/kg_icon.png') }}" alt="{{ config('app.name') }}"
                         class="h-[84px] w-[84px] shrink-0 rounded-3xl object-contain shadow-[0_16px_40px_-14px_rgba(79,70,229,0.35)] lg:h-[116px] lg:w-[116px]">

                    <h1 class="mt-6 text-3xl font-extrabold leading-tight tracking-tight text-slate-900 lg:mt-8 lg:text-[2.75rem]">
                        {{ __('Khidmatguzar') }}<br class="hidden lg:block">
                        <span class="lg:inline"> {{ __('Attendance') }}</span>
                    </h1>
                    <p class="mt-3 text-base font-medium text-indigo-500 lg:mt-4 lg:text-lg">
                        {{ __('Mark Today. Build Tomorrow.') }}
                    </p>

                    <span class="mt-5 hidden h-1 w-10 rounded-full bg-blue-500 lg:block"></span>

                    {{-- Desktop: vertical feature list, generously spaced. --}}
                    <div class="mt-10 hidden w-full flex-col gap-6 lg:flex">
                        @foreach ([
                            ['icon' => 'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a4 4 0 0 1 3-3.87m5-3.13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6 0a4 4 0 1 0-2.83-6.83', 'title' => 'Simple Attendance', 'desc' => 'Quick and effortless'],
                            ['icon' => 'M9 17V9m3 8V5m3 12v-4M5 21h14a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1Z', 'title' => 'Useful Insights', 'desc' => 'Make better decisions'],
                            ['icon' => 'M12 3 4 6v6c0 4.5 3.4 8.7 8 9 4.6-.3 8-4.5 8-9V6l-8-3Z', 'title' => 'Secure & Reliable', 'desc' => 'Your data is always protected'],
                        ] as $feature)
                            <div class="flex items-center gap-4 text-left">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/80 text-indigo-600 shadow-sm ring-1 ring-indigo-100/80 backdrop-blur">
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

                {{-- Compact white glass login card — fixed width, not stretched, floats independently of the brand panel. --}}
                <div class="w-full max-w-md lg:w-[420px] lg:shrink-0">
                    <div class="kg-glass-card rounded-[1.75rem] p-7 sm:p-9">
                        {{ $slot }}
                    </div>
                </div>
            </div>

            {{-- Mobile-only: compact horizontal feature row below the card, matching the reference's mobile footer strip. --}}
            <div class="relative z-10 mt-10 grid w-full max-w-md grid-cols-3 gap-3 text-center lg:hidden">
                @foreach ([
                    ['icon' => 'M13 10V3L4 14h7v7l9-11h-7Z', 'title' => 'Simple', 'desc' => 'Quick Access'],
                    ['icon' => 'M9 17V9m3 8V5m3 12v-4M5 21h14a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1Z', 'title' => 'Insightful', 'desc' => 'Stay Informed'],
                    ['icon' => 'M12 3 4 6v6c0 4.5 3.4 8.7 8 9 4.6-.3 8-4.5 8-9V6l-8-3Z', 'title' => 'Reliable', 'desc' => 'Your Data is Safe'],
                ] as $feature)
                    <div class="flex flex-col items-center gap-1.5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/70 text-indigo-600 shadow-sm ring-1 ring-indigo-100/80">
                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $feature['icon'] }}" /></svg>
                        </span>
                        <span class="text-xs font-semibold text-slate-700">{{ __($feature['title']) }}</span>
                        <span class="text-[11px] text-slate-500">{{ __($feature['desc']) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </body>
</html>

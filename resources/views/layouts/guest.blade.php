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
        <meta name="theme-color" content="#0F1E3D">
        <link rel="manifest" href="/manifest.json">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
        <div class="kg-guest-shell flex min-h-screen flex-col items-center justify-center px-4">
            <div class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-navy-900 text-white">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1m8-4H6a2 2 0 0 0-2 2v16l4-2 4 2 4-2 4 2V6a2 2 0 0 0-2-2Z" />
                </svg>
            </div>
            <p class="mb-6 text-sm font-medium text-slate-500">{{ config('app.name') }}</p>

            <div class="w-full max-w-sm rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>

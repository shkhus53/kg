<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0F1E3D">
        <link rel="manifest" href="/manifest.json">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Khidmatguzar">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
        {{-- Desktop (lg:+): persistent sidebar, content uses the freed-up
             width instead of centering in a phone-width column. Mobile/
             tablet below lg: unchanged — same narrow, thumb-friendly shell
             as before, bottom-nav still drives navigation. --}}
        <x-shell.sidebar-nav />

        <div class="mx-auto min-h-screen max-w-md bg-slate-50 pb-24 sm:max-w-2xl lg:max-w-none lg:pb-8 lg:pl-64">
            <div class="lg:mx-auto lg:max-w-5xl xl:max-w-6xl 2xl:max-w-7xl">
                {{ $header ?? '' }}

                <main class="px-5 py-5 lg:px-8 lg:py-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-shell.bottom-nav />

        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js');
                });
            }
        </script>
    </body>
</html>

@php
    $items = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => request()->routeIs('dashboard')],
        ['label' => 'Sessions', 'route' => 'sessions.index', 'active' => request()->routeIs('sessions.*') && !request()->routeIs('sessions.imports.*')],
    ];
@endphp
<nav class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white">
    <div class="mx-auto flex max-w-md items-center justify-around px-2 py-2 sm:max-w-2xl lg:max-w-4xl">
        <a href="{{ route('dashboard') }}" class="flex flex-col items-center gap-1 rounded-lg px-3 py-1.5 {{ request()->routeIs('dashboard') ? 'text-navy-900' : 'text-slate-400' }}">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V9.5Z" />
            </svg>
            <span class="text-[10px] font-medium">{{ __('Home') }}</span>
        </a>

        <a href="{{ route('sessions.index') }}" class="flex flex-col items-center gap-1 rounded-lg px-3 py-1.5 {{ request()->routeIs('sessions.*') && !request()->routeIs('sessions.imports.*') ? 'text-navy-900' : 'text-slate-400' }}">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" />
            </svg>
            <span class="text-[10px] font-medium">{{ __('Sessions') }}</span>
        </a>

        @if (auth()->user()->canManageSessions())
            <a href="{{ route('attendance.shell.live-redirect') }}" class="flex flex-col items-center" title="{{ __('Live Attendance') }}">
                <span class="-mt-7 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg shadow-emerald-500/30">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7" stroke-linecap="round" />
                        <path stroke-linecap="round" d="m20 20-3.5-3.5" />
                    </svg>
                </span>
            </a>
        @else
            <div class="flex flex-col items-center">
                <span class="-mt-7 flex h-12 w-12 items-center justify-center rounded-full bg-slate-300 text-white" title="{{ __('Live Attendance (not available for your role)') }}">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7" stroke-linecap="round" />
                        <path stroke-linecap="round" d="m20 20-3.5-3.5" />
                    </svg>
                </span>
            </div>
        @endif

        <a href="{{ route('reports.index') }}" class="flex flex-col items-center gap-1 rounded-lg px-3 py-1.5 {{ request()->routeIs('reports.*') ? 'text-navy-900' : 'text-slate-400' }}">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4M5 21h14a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1Z" />
            </svg>
            <span class="text-[10px] font-medium">{{ __('Reports') }}</span>
        </a>

        <div x-data="{ open: false }" class="relative flex flex-col items-center" @keyup.escape="open = false">
            <button type="button" @click="open = !open" @click.outside="open = false"
                    class="flex flex-col items-center gap-1 rounded-lg px-3 py-1.5 {{ request()->routeIs('analytics.*') ? 'text-navy-900' : 'text-slate-400' }}">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="5" cy="12" r="1.5" fill="currentColor" stroke="none" />
                    <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
                    <circle cx="19" cy="12" r="1.5" fill="currentColor" stroke="none" />
                </svg>
                <span class="text-[10px] font-medium">{{ __('More') }}</span>
            </button>

            <div x-show="open" x-cloak x-transition @click="open = false"
                 class="absolute bottom-14 right-0 z-50 w-44 rounded-xl border border-slate-100 bg-white p-1.5 text-slate-700 shadow-lg">
                <a href="{{ route('analytics.overview') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-50">
                    <svg class="h-4 w-4 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4" /></svg>
                    {{ __('Analytics') }}
                </a>
                <a href="{{ route('analytics.profile-search') }}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-50">
                    <svg class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm-8 8a6 6 0 0 0-6 6h20a6 6 0 0 0-6-6H8Z" /></svg>
                    {{ __('Khidmatguzars') }}
                </a>
            </div>
        </div>
    </div>
</nav>

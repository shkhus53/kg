{{-- Phase 10: persistent desktop sidebar (lg:+). Bottom-nav remains the
     mobile/tablet pattern (see bottom-nav.blade.php, now lg:hidden) — this
     is a distinct desktop-appropriate composition, not the same nav
     stretched wider. Same routes/permissions as bottom-nav, just laid out
     for mouse/keyboard with room to show every item flat (no "More" popover
     needed when there's vertical space). --}}
@php
    $navItem = fn (string $route, bool $active) => $active
        ? 'bg-navy-900/5 text-navy-900'
        : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900';
@endphp
<aside class="fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r border-slate-200 bg-white lg:flex">
    <div class="flex items-center gap-2.5 px-5 py-5">
        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-navy-900 text-white">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1m8-4H6a2 2 0 0 0-2 2v16l4-2 4 2 4-2 4 2V6a2 2 0 0 0-2-2Z" /></svg>
        </span>
        <span class="text-sm font-semibold text-slate-900">{{ __('KG Attendance') }}</span>
    </div>

    <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
        <a href="{{ route('dashboard') }}" class="kg-tap flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $navItem('dashboard', request()->routeIs('dashboard')) }}">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V9.5Z" /></svg>
            {{ __('Dashboard') }}
        </a>

        <a href="{{ route('sessions.index') }}" class="kg-tap flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $navItem('sessions', request()->routeIs('sessions.*') && !request()->routeIs('sessions.imports.*')) }}">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" /></svg>
            {{ __('Sessions') }}
        </a>

        @if (auth()->user()->canManageSessions())
            <a href="{{ route('attendance.shell.live-redirect') }}" class="kg-tap flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $navItem('attendance', request()->routeIs('attendance.shell.live*')) }}">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" stroke-linecap="round" /><path stroke-linecap="round" d="m20 20-3.5-3.5" /></svg>
                {{ __('Live Attendance') }}
            </a>
        @endif

        <a href="{{ route('reports.index') }}" class="kg-tap flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $navItem('reports', request()->routeIs('reports.*')) }}">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4M5 21h14a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1Z" /></svg>
            {{ __('Reports') }}
        </a>

        <p class="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Analytics') }}</p>

        <a href="{{ route('analytics.overview') }}" class="kg-tap flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $navItem('analytics', request()->routeIs('analytics.overview') || request()->routeIs('analytics.departments') || request()->routeIs('analytics.insights')) }}">
            <svg class="h-5 w-5 shrink-0 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V9m3 8V5m3 12v-4" /></svg>
            {{ __('Overview') }}
        </a>

        <a href="{{ route('analytics.profile-search') }}" class="kg-tap flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $navItem('directory', request()->routeIs('analytics.profile-search') || request()->routeIs('analytics.profile')) }}">
            <svg class="h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm-8 8a6 6 0 0 0-6 6h20a6 6 0 0 0-6-6H8Z" /></svg>
            {{ __('Khidmatguzars') }}
        </a>

        @can('view_audit_log')
            <a href="{{ route('audit.index') }}" class="kg-tap flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $navItem('audit', request()->routeIs('audit.index')) }}">
                <svg class="h-5 w-5 shrink-0 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l4.414 4.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z" /></svg>
                {{ __('Audit Log') }}
            </a>
            <a href="{{ route('analytics.operators') }}" class="kg-tap flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ $navItem('operators', request()->routeIs('analytics.operators')) }}">
                <svg class="h-5 w-5 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm-8 8a6 6 0 0 0-6 6h20a6 6 0 0 0-6-6H8Z" /></svg>
                {{ __('Operator Analytics') }}
            </a>
        @endcan
    </nav>

    <div class="border-t border-slate-100 p-3">
        <div x-data="{ open: false }" class="relative">
            <button type="button" @click="open = !open" @click.outside="open = false" aria-label="{{ __('Account menu') }}" aria-haspopup="true" :aria-expanded="open.toString()"
                    class="kg-tap flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm hover:bg-slate-50">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-navy-900 text-xs font-semibold text-white">
                    {{ mb_substr(auth()->user()->name, 0, 1) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium text-slate-900">{{ auth()->user()->name }}</span>
                    <span class="block text-xs text-slate-400">{{ ucfirst(auth()->user()->role) }}</span>
                </span>
            </button>
            <div x-show="open" x-cloak x-transition class="kg-menu-enter absolute bottom-full left-0 mb-2 w-full rounded-xl border border-slate-100 bg-white p-1.5 shadow-lg">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">{{ __('Log out') }}</button>
                </form>
            </div>
        </div>
    </div>
</aside>

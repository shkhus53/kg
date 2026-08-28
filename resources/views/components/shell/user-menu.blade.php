<div x-data="{ open: false }" class="relative">
    <button @click="open = !open" @click.outside="open = false" type="button"
            class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h16" />
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition
         class="absolute left-0 top-10 z-50 w-48 rounded-xl border border-slate-100 bg-white p-2 text-slate-700 shadow-lg">
        <div class="px-2 py-1.5 text-xs">
            <p class="font-semibold text-slate-900">{{ auth()->user()->name }}</p>
            <p class="text-slate-400">{{ ucfirst(auth()->user()->role) }}</p>
        </div>
        <div class="my-1 border-t border-slate-100"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-50">
                {{ __('Log out') }}
            </button>
        </form>
    </div>
</div>

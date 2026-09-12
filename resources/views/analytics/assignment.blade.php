<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Assignment" :back-url="route('analytics.profile', $assignment->khidmatguzar)">
            <x-slot:actions>
                <x-shell.badge :tone="$assignment->current_status === 'present' ? 'green' : ($assignment->current_status === 'absent' ? 'red' : 'orange')">
                    {{ $assignment->current_status }}
                </x-shell.badge>
            </x-slot:actions>
        </x-shell.page-header>
    </x-slot>

    <div class="space-y-4">
        <x-shell.breadcrumb :items="[
            ['label' => 'Directory', 'url' => route('analytics.profile-search')],
            ['label' => $assignment->khidmatguzar->full_name, 'url' => route('analytics.profile', $assignment->khidmatguzar)],
            ['label' => 'Assignment', 'url' => null],
        ]" />

        <x-shell.card>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-slate-400">{{ __('Session') }}</dt><dd class="font-medium text-slate-900">{{ $assignment->dutySession->name }}</dd></div>
                <div><dt class="text-slate-400">{{ __('Date') }}</dt><dd class="font-medium text-slate-900">{{ $assignment->dutySession->date->format('d M Y') }}</dd></div>
                <div><dt class="text-slate-400">{{ __('Department') }}</dt><dd class="font-medium text-slate-900">{{ $assignment->department->name }}</dd></div>
                <div><dt class="text-slate-400">{{ __('Block') }}</dt><dd class="font-medium text-slate-900">{{ $assignment->block_name ?: '—' }}</dd></div>
                <div><dt class="text-slate-400">{{ __('Day') }}</dt><dd class="font-medium text-slate-900">{{ $assignment->day_alias ?: $assignment->day ?: '—' }}</dd></div>
                <div><dt class="text-slate-400">{{ __('Seat') }}</dt><dd class="font-medium text-slate-900">{{ $assignment->seat ?: '—' }}</dd></div>
            </dl>
        </x-shell.card>

        <div>
            <h2 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Attendance Events') }}</h2>
            @if ($events->isEmpty())
                <x-shell.empty-state title="{{ __('No events recorded yet — still Pending') }}" />
            @else
                <div class="space-y-2">
                    @foreach ($events as $event)
                        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-medium text-slate-900">{{ ucfirst($event->action) }}</p>
                                <x-shell.badge :tone="$event->action === 'present' ? 'green' : 'red'">{{ $event->context }}</x-shell.badge>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                {{ $event->performed_at->toIst()->format('d M Y H:i:s') }} &middot; {{ __('By') }}: {{ $event->performedBy->name ?? '—' }}
                            </p>
                            @if ($event->remark)
                                <p class="mt-1 text-xs text-slate-500">{{ __('Remark') }}: {{ $event->remark }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

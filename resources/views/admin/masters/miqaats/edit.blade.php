<x-app-layout>
    <x-slot name="header">
        <x-shell.page-header title="Edit Miqaat" :subtitle="$miqaat->name" :back-url="route('masters.miqaats.index')" />
    </x-slot>

    <div class="space-y-5">
        <x-shell.card>
            <form method="POST" action="{{ route('masters.miqaats.update', $miqaat) }}" class="grid grid-cols-2 gap-3">
                @csrf
                @method('PUT')
                <div class="col-span-2">
                    <x-input-label for="name" :value="__('Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $miqaat->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="code" :value="__('Code (optional)')" />
                    <x-text-input id="code" name="code" type="text" class="mt-1 block w-full" :value="old('code', $miqaat->code)" />
                </div>
                <div class="col-span-2">
                    <x-input-label for="description" :value="__('Description (optional)')" />
                    <textarea id="description" name="description" rows="3" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('description', $miqaat->description) }}</textarea>
                </div>
                <div class="col-span-2 grid grid-cols-2 gap-3">
                    <x-shell.button tone="outline" href="{{ route('masters.miqaats.index') }}">{{ __('Cancel') }}</x-shell.button>
                    <x-shell.button tone="primary" type="submit">{{ __('Save Changes') }}</x-shell.button>
                </div>
            </form>
        </x-shell.card>

        <div>
            <h2 class="mb-3 text-sm font-semibold text-slate-500">{{ __('Change History') }}</h2>
            @php $history = app(\App\Services\MasterDataAuditService::class)->history('miqaat', $miqaat->id); @endphp
            @if ($history->isEmpty())
                <x-shell.empty-state title="{{ __('No changes recorded yet') }}" />
            @else
                <div class="space-y-2">
                    @foreach ($history as $entry)
                        <div class="rounded-xl border border-slate-100 bg-white p-3 text-sm">
                            <p class="font-medium text-slate-900">
                                @if ($entry->field === 'status')
                                    {{ ucfirst($entry->new_value) }}
                                @else
                                    {{ ucfirst($entry->field) }}: <span class="text-slate-400 line-through">{{ $entry->old_value ?: '—' }}</span> → <span class="font-semibold text-blue-700">{{ $entry->new_value }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-slate-400">{{ $entry->changedBy->name }} &middot; {{ $entry->changed_at->toIst()->format('d M Y H:i') }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

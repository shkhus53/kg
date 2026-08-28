@props(['breakdown', 'rows' => ['scheduled' => 'Scheduled', 'present' => 'Present', 'absent' => 'Absent', 'pending' => 'Pending']])

{{-- Compact secondary gender split — never the primary number, always a smaller sub-row
     under the totals it belongs to. Keeps mobile card counts from tripling. --}}
<div {{ $attributes->merge(['class' => 'space-y-1.5 text-xs']) }}>
    @foreach ($rows as $key => $label)
        @if (isset($breakdown[$key]))
            <div class="flex items-center justify-between text-slate-500">
                <span>{{ __($label) }}</span>
                <span>
                    <span class="text-blue-600">{{ __('Male') }} {{ $breakdown[$key]['male'] }}</span>
                    <span class="mx-1 text-slate-300">&middot;</span>
                    <span class="text-violet-600">{{ __('Female') }} {{ $breakdown[$key]['female'] }}</span>
                    @if ($breakdown[$key]['unknown'] > 0)
                        <span class="mx-1 text-slate-300">&middot;</span>
                        <span class="text-slate-400">{{ __('Unknown') }} {{ $breakdown[$key]['unknown'] }}</span>
                    @endif
                </span>
            </div>
        @endif
    @endforeach
</div>

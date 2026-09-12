@props(['items'])
{{-- $items: array of ['label' => string, 'url' => string|null]. Last item (or any with url=null) renders as plain text, not a link. --}}
<nav class="mb-1 flex flex-wrap items-center gap-1 text-xs text-slate-400">
    @foreach ($items as $i => $item)
        @if ($i > 0)
            <span>/</span>
        @endif
        @if ($item['url'] ?? null)
            <a href="{{ $item['url'] }}" class="text-blue-600 hover:underline">{{ $item['label'] }}</a>
        @else
            <span class="font-medium text-slate-600">{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>

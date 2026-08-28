@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-slate-300 bg-white text-slate-900 focus:border-blue-500 focus:ring-blue-500 rounded-xl shadow-sm']) }}>

@props([
    'variant' => 'primary',
    'type' => 'button',
])

@php
    $classes = [
        'primary' => 'bg-blue-600 text-white hover:bg-blue-700',
        'success' => 'bg-emerald-600 text-white hover:bg-emerald-700',
        'secondary' => 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
        'warning' => 'bg-amber-600 text-white hover:bg-amber-700',
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        'ghost-danger' => 'bg-red-50 text-red-700 hover:bg-red-100',
    ][$variant] ?? 'bg-blue-600 text-white hover:bg-blue-700';
@endphp

<button type="{{ $type }}" {{ $attributes->merge(['class' => 'inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-semibold shadow-sm transition disabled:cursor-not-allowed disabled:opacity-60 '.$classes]) }}>
    {{ $slot }}
</button>

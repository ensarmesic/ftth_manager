@php
    $normalized = strtolower((string) ($variant ?? $value ?? 'default'));
    $classes = match ($normalized) {
        'active', 'connected', 'in_service', 'built', 'completed', 'success' => 'bg-emerald-100 text-emerald-800',
        'planned', 'planning', 'in_progress', 'warning' => 'bg-amber-100 text-amber-800',
        'paused', 'cancelled', 'danger', 'error' => 'bg-red-100 text-red-800',
        default => 'bg-slate-100 text-slate-700',
    };
    $labels = [
        'active' => 'Aktivan',
        'connected' => 'Spojen',
        'in_service' => 'U servisu',
        'built' => 'Izgrađeno',
        'completed' => 'Završen',
        'planned' => 'Planirano',
        'planning' => 'Planiranje',
        'in_progress' => 'U toku',
        'paused' => 'Pauziran',
        'cancelled' => 'Otkazano',
    ];
@endphp

<span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold {{ $classes }}">
    {{ $labels[$normalized] ?? ($value ?? '-') }}
</span>

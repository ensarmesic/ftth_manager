@php
    $normalized = strtolower((string) ($variant ?? $value ?? 'default'));
    $color = match ($normalized) {
        'active', 'connected', 'in_service', 'built', 'completed', 'success' => 'green',
        'planned', 'planning', 'in_progress'                                  => 'amber',
        'paused', 'cancelled', 'danger', 'error'                              => 'red',
        'warning'                                                              => 'amber',
        default                                                                => 'slate',
    };
    $labels = [
        'active' => 'Aktivan', 'connected' => 'Spojen', 'in_service' => 'U servisu',
        'built' => 'Izgrađeno', 'completed' => 'Završen', 'planned' => 'Planirano',
        'planning' => 'Planiranje', 'in_progress' => 'U toku',
        'paused' => 'Pauziran', 'cancelled' => 'Otkazano',
    ];
@endphp
<span class="ftth-badge {{ $color }}">
    <span class="ftth-badge-dot"></span>
    {{ $labels[$normalized] ?? ($value ?? '-') }}
</span>

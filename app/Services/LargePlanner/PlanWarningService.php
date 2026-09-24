<?php

namespace App\Services\LargePlanner;

class PlanWarningService
{
    private const CRITICAL_CODES = [
        'house_without_route',
        'odo_drop_limit_exceeded',
        'missing_source_odf',
        'odo_without_odf_route',
        'odf_without_primary_route',
        'cable_capacity_exceeded',
    ];

    public function collect(array $clustering, array $placement, array $odfPlan, array $routes, array $capacity): array
    {
        $warnings = collect($clustering['unroutable_houses'] ?? [])->map(fn (array $house) => [
            'code' => 'house_without_route',
            'house_id' => $house['id'],
            'house_label' => $house['label'],
            'reason' => $house['reason'],
            'distance_m' => $house['distance_m'],
            'message' => $this->houseMessage($house),
        ])->concat($placement['warnings'] ?? [])
            ->concat($odfPlan['warnings'] ?? [])
            ->concat($routes['warnings'] ?? [])
            ->concat($capacity['warnings'] ?? [])
            ->map(function (array $warning): array {
                $warning['severity'] = in_array($warning['code'] ?? '', self::CRITICAL_CODES, true) ? 'error' : 'warning';

                return $warning;
            })
            ->unique(fn (array $warning) => implode('|', [
                $warning['code'] ?? '',
                $warning['house_id'] ?? '',
                $warning['odo_key'] ?? '',
                $warning['odf_key'] ?? '',
                $warning['segment_key'] ?? '',
            ]))
            ->values();

        return [
            'items' => $warnings->all(),
            'summary' => [
                'total' => $warnings->count(),
                'errors' => $warnings->where('severity', 'error')->count(),
                'warnings' => $warnings->where('severity', 'warning')->count(),
                'houses_without_route' => $warnings->where('code', 'house_without_route')->count(),
                'capacity_exceeded' => $warnings->where('code', 'cable_capacity_exceeded')->count(),
            ],
            'can_confirm' => $warnings->where('severity', 'error')->isEmpty(),
        ];
    }

    private function houseMessage(array $house): string
    {
        $label = $house['label'] ?: '#'.$house['id'];

        return match ($house['reason']) {
            'missing_coordinates' => "Kuća {$label} nema koordinate.",
            'outside_max_drop' => "Kuća {$label} nema dostupnu trasu unutar maksimalnog dropa.",
            default => "Kuća {$label} nema valjanu rutu.",
        };
    }
}

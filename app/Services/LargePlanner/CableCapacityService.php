<?php

namespace App\Services\LargePlanner;

use App\Models\Project;

class CableCapacityService
{
    private const CAPACITIES = [4, 12, 24, 48];

    public function calculate(Project $project, array $placement, array $odfPlan, array $routes): array
    {
        $reservePercent = (float) $project->largePlannerSetting()->firstOrFail()->fiber_reserve_percent;
        $odoByKey = collect($placement['odos'])->keyBy('key');
        $odfByKey = collect($odfPlan['odfs'] ?? [])->keyBy('key');
        $annotated = ['primary_routes' => [], 'secondary_routes' => [], 'drop_routes' => []];
        $segments = [];
        $warnings = [];

        foreach ($odfPlan['primary_routes'] ?? [] as $route) {
            $odf = $odfByKey->get($route['to_odf_key']);
            $load = collect($odf['odo_keys'] ?? [])->sum(fn (string $key) => max(1, (int) ($odoByKey->get($key)['occupancy'] ?? 1)));
            $annotated['primary_routes'][] = $this->annotate($route, max(1, $load), $reservePercent);
        }
        foreach ($routes['secondary_routes'] ?? [] as $route) {
            $load = max(1, (int) ($odoByKey->get($route['odo_key'])['occupancy'] ?? 1));
            $annotated['secondary_routes'][] = $this->annotate($route, $load, $reservePercent);
        }
        foreach ($routes['drop_routes'] ?? [] as $route) {
            $annotated['drop_routes'][] = $this->annotate($route, 1, $reservePercent);
        }

        foreach ($annotated as $routeType => $items) {
            foreach ($items as $route) {
                foreach (array_slice($route['path'], 0, -1, true) as $index => $from) {
                    $to = $route['path'][$index + 1];
                    $key = $this->segmentKey($from, $to);
                    $segments[$key] ??= [
                        'key' => $key,
                        'path' => $this->orderedPath($from, $to),
                        'route_types' => [],
                        'route_keys' => [],
                        'load_fibers' => 0,
                    ];
                    $segments[$key]['route_types'][] = str_replace('_routes', '', $routeType);
                    $segments[$key]['route_keys'][] = $route['key'];
                    $segments[$key]['load_fibers'] += $route['load_fibers'];
                }
            }
        }

        $segments = collect($segments)->sortKeys()->map(function (array $segment) use ($reservePercent, &$warnings): array {
            $segment['route_types'] = array_values(array_unique($segment['route_types']));
            $segment['route_keys'] = array_values(array_unique($segment['route_keys']));
            $sizing = $this->sizing($segment['load_fibers'], $reservePercent);
            $segment += $sizing;
            if ($sizing['overloaded']) {
                $warnings[] = [
                    'code' => 'cable_capacity_exceeded',
                    'segment_key' => $segment['key'],
                    'message' => "Segment traži {$sizing['required_fibers']} vlakana, više od podržanih 48F.",
                ];
            }

            return $segment;
        })->values()->all();

        return [
            'routes' => $annotated,
            'segments' => $segments,
            'warnings' => $warnings,
            'summary' => [
                'segments' => count($segments),
                'overloaded_segments' => count($warnings),
                'reserve_percent' => $reservePercent,
                'capacities' => self::CAPACITIES,
            ],
        ];
    }

    private function annotate(array $route, int $load, float $reservePercent): array
    {
        return $route + $this->sizing($load, $reservePercent);
    }

    private function sizing(int $load, float $reservePercent): array
    {
        $reserve = (int) ceil($load * $reservePercent / 100);
        $required = $load + $reserve;
        $capacity = collect(self::CAPACITIES)->first(fn (int $candidate) => $candidate >= $required);

        return [
            'load_fibers' => $load,
            'reserve_fibers' => $reserve,
            'required_fibers' => $required,
            'fiber_count' => $capacity,
            'overloaded' => $capacity === null,
        ];
    }

    private function segmentKey(array $from, array $to): string
    {
        return collect($this->orderedPath($from, $to))
            ->map(fn (array $point) => number_format((float) $point[0], 7, '.', '').','.number_format((float) $point[1], 7, '.', ''))
            ->implode('|');
    }

    private function orderedPath(array $from, array $to): array
    {
        return [$from, $to] <= [$to, $from] ? [$from, $to] : [$to, $from];
    }
}

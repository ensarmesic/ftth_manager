<?php

namespace App\Services\LargePlanner;

use App\Models\Project;

class CableCapacityService
{
    private const CAPACITIES = [4, 12, 24, 48, 72, 96, 144];

    private const PORTS_PER_SPLITTER = 4;

    public function calculate(Project $project, array $placement, array $odfPlan, array $routes): array
    {
        $reservePercent = (float) $project->largePlannerSetting()->firstOrFail()->fiber_reserve_percent;
        $odoByKey = collect($placement['odos'])->keyBy('key');
        $annotated = ['primary_routes' => [], 'secondary_routes' => [], 'drop_routes' => []];
        $segments = [];
        $warnings = [];

        $odfLoads = [];
        foreach ($routes['secondary_routes'] ?? [] as $route) {
            $identity = $this->odfIdentity($route);
            $odfLoads[$identity] = ($odfLoads[$identity] ?? 0) + collect($route['odo_keys'] ?? [$route['odo_key']])->sum(
                fn (string $key) => $this->splitterCount((int) ($odoByKey->get($key)['occupancy'] ?? 1))
            );
        }
        foreach ($odfPlan['primary_routes'] ?? [] as $route) {
            $load = max(1, $odfLoads[$this->odfIdentity($route, 'from')] ?? 0, $odfLoads[$this->odfIdentity($route, 'to')] ?? 0);
            $annotated['primary_routes'][] = $this->annotate($route, max(1, $load), $reservePercent);
        }
        foreach ($routes['secondary_routes'] ?? [] as $route) {
            $load = collect($route['odo_keys'] ?? [$route['odo_key']])->sum(
                fn (string $key) => $this->splitterCount((int) ($odoByKey->get($key)['occupancy'] ?? 1))
            );
            $annotated['secondary_routes'][] = $this->annotate($route, $load, $reservePercent);
        }
        foreach ($routes['drop_routes'] ?? [] as $route) {
            $annotated['drop_routes'][] = $this->annotate($route, 1, $reservePercent);
        }

        $odfCapacities = collect($odfPlan['odfs'] ?? [])->mapWithKeys(fn (array $odf) => [
            'key:'.$odf['key'] => (int) ($odf['fiber_capacity'] ?? 144),
        ]);
        $projectOdfs = $project->odfs()->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $manualOdfs = $projectOdfs->whereNull('import_batch')->values();
        if ($manualOdfs->isNotEmpty()) {
            $projectOdfs = $manualOdfs;
        }
        foreach ($projectOdfs as $odf) {
            $odfCapacities->put('id:'.$odf->id, (int) $odf->fiber_capacity);
        }
        $odfSizing = $odfCapacities->map(function (int $capacity, string $identity) use ($odfLoads, $reservePercent, &$warnings): array {
            $load = (int) ($odfLoads[$identity] ?? 0);
            $reserve = (int) ceil($load * $reservePercent / 100);
            $required = $load + $reserve;
            $overloaded = $required > $capacity;
            if ($overloaded) {
                $warnings[] = ['code' => 'odf_fiber_capacity_exceeded', 'odf_key' => $identity, 'message' => "ODF {$identity} traži {$required} vlakana sa rezervom, a podržava {$capacity}F."];
            }

            return compact('identity', 'capacity', 'load', 'reserve', 'required', 'overloaded');
        })->values()->all();

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
                    'message' => "Segment traži {$sizing['required_fibers']} vlakana, više od podržanih ".max(self::CAPACITIES).'F.',
                ];
            }

            return $segment;
        })->values()->all();

        return [
            'routes' => $annotated,
            'odfs' => $odfSizing,
            'segments' => $segments,
            'warnings' => $warnings,
            'summary' => [
                'segments' => count($segments),
                'overloaded_segments' => collect($warnings)->where('code', 'cable_capacity_exceeded')->count(),
                'overloaded_odfs' => collect($odfSizing)->where('overloaded', true)->count(),
                'reserve_percent' => $reservePercent,
                'capacities' => self::CAPACITIES,
            ],
        ];
    }

    private function odfIdentity(array $route, string $side = ''): string
    {
        $prefix = $side === '' ? '' : $side.'_';
        $key = $route[$prefix.'odf_key'] ?? null;

        return $key !== null ? 'key:'.$key : 'id:'.(int) ($route[$prefix.'odf_id'] ?? 0);
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

    private function splitterCount(int $houses): int
    {
        return max(1, (int) ceil($houses / self::PORTS_PER_SPLITTER));
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

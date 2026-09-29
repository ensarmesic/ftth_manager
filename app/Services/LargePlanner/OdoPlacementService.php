<?php

namespace App\Services\LargePlanner;

use App\Models\Project;
use App\Services\GeometryService;

class OdoPlacementService
{
    public function __construct(
        private readonly GeometryService $geometry,
        private readonly NetworkRouteProposalService $routes,
    ) {}

    public function propose(Project $project, array $clustering, array $graph): array
    {
        $settings = $project->largePlannerSetting()->firstOrFail();
        $maxDrop = (int) $settings->max_drop_length_m;
        $proposals = [];
        $warnings = [];
        $planningGroups = collect($clustering['clusters'])
            ->flatMap(fn (array $cluster) => $cluster['houses'])
            ->unique('house_id')
            ->groupBy(fn (array $house) => implode(':', [
                $house['zone_id'] ?? 0,
                $house['component'],
                $house['corridor_id'],
            ]))
            ->flatMap(function ($houses) use ($settings) {
                $houses = $houses->sortBy(fn (array $house) => [$house['corridor_chainage_m'] ?? 0, $house['house_id']])->values();
                $windows = $houses->chunk((int) $settings->odo_capacity);

                return $windows->map(function ($window): array {
                    $first = $window->first();

                    return [
                        'key' => 'corridor-'.$first['corridor_id'],
                        'zone_id' => $first['zone_id'],
                        'component' => $first['component'],
                        'houses' => $window->values()->all(),
                    ];
                });
            })
            ->values();

        foreach ($planningGroups as $cluster) {
            $remaining = collect($cluster['houses'])->keyBy('house_id');
            $part = 1;
            while ($remaining->isNotEmpty()) {
                $evaluated = $remaining
                    ->unique(fn (array $house) => implode(',', $house['access_point']))
                    ->map(fn (array $candidate) => $this->evaluate(
                        $graph,
                        $candidate['access_point'],
                        $candidate['corridor_id'],
                        $remaining->values()->all(),
                        $settings->optimization_goal,
                        $maxDrop,
                    ))
                    ->sortBy(fn (array $candidate) => [-$candidate['covered_houses'], $candidate['score'], $candidate['point'][0], $candidate['point'][1]])
                    ->values();
                $candidate = $evaluated->first();
                $selectedHouses = $remaining
                    ->filter(fn (array $house) => ($candidate['house_distances'][$house['house_id']] ?? INF) <= $maxDrop)
                    ->sortBy(fn (array $house) => [$candidate['house_distances'][$house['house_id']], $house['house_id']])
                    ->take((int) $settings->odo_capacity)
                    ->values();

                if ($selectedHouses->isEmpty()) {
                    $warnings[] = [
                        'code' => 'odo_drop_limit_exceeded',
                        'cluster_key' => $cluster['key'],
                        'message' => "Za {$cluster['key']} nije pronađen ODO položaj unutar maksimalnih {$maxDrop} m.",
                    ];
                    break;
                }

                $selected = $this->evaluate(
                    $graph,
                    $candidate['point'],
                    $candidate['corridor_id'],
                    $selectedHouses->all(),
                    $settings->optimization_goal,
                    $maxDrop,
                );
                $number = count($proposals) + 1;
                $proposals[] = [
                    'key' => 'odo-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                    'provisional_name' => 'ODO-P-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                    'cluster_key' => $cluster['key'].'-'.str_pad((string) $part, 2, '0', STR_PAD_LEFT),
                    'zone_id' => $cluster['zone_id'],
                    'component' => $cluster['component'],
                    'point' => $selected['point'],
                    'corridor_id' => $selected['corridor_id'],
                    'capacity' => (int) $settings->odo_capacity,
                    'occupancy' => $selectedHouses->count(),
                    'house_ids' => $selectedHouses->pluck('house_id')->all(),
                    'total_drop_length_m' => $selected['total_drop_length_m'],
                    'max_drop_distance_m' => $selected['max_drop_distance_m'],
                    'within_drop_limit' => true,
                ];
                $remaining = $remaining->except($selectedHouses->pluck('house_id')->all());
                $part++;
            }
        }

        return [
            'odos' => $proposals,
            'warnings' => $warnings,
            'summary' => [
                'proposed_odos' => count($proposals),
                'houses' => array_sum(array_column($proposals, 'occupancy')),
                'total_drop_length_m' => array_sum(array_column($proposals, 'total_drop_length_m')),
                'warnings' => count($warnings),
            ],
        ];
    }

    private function evaluate(array $graph, array $point, int $corridorId, array $houses, ?string $goal, int $maxDrop): array
    {
        $distances = [];
        foreach ($houses as $house) {
            $corridorPath = $this->routes->pathThroughGraph($graph, $point, $house['access_point']);
            $distances[$house['house_id']] = $corridorPath === null
                ? INF
                : $corridorPath['length_m'] + $this->geometry->distanceBetweenPoints($house['access_point'], $house['house_point']);
        }
        $total = array_sum($distances);
        $maximum = max($distances);
        $score = match ($goal) {
            'min_cable' => $total,
            'weighted' => $total + ($maximum * 2),
            default => $total + $maximum,
        };

        return [
            'point' => $point,
            'corridor_id' => $corridorId,
            'total_drop_length_m' => (int) round($total),
            'max_drop_distance_m' => (int) ceil($maximum - 0.01),
            'violations' => count(array_filter($distances, fn (float $distance) => $distance > $maxDrop)),
            'covered_houses' => count(array_filter($distances, fn (float $distance) => $distance <= $maxDrop)),
            'house_distances' => $distances,
            'score' => $score,
        ];
    }
}

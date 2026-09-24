<?php

namespace App\Services\LargePlanner;

use App\Models\Project;
use App\Services\GeometryService;

class OdoPlacementService
{
    public function __construct(private readonly GeometryService $geometry) {}

    public function propose(Project $project, array $clustering): array
    {
        $settings = $project->largePlannerSetting()->firstOrFail();
        $maxDrop = (int) $settings->max_drop_length_m;
        $proposals = [];
        $warnings = [];

        foreach ($clustering['clusters'] as $index => $cluster) {
            $candidates = collect($cluster['houses'])
                ->unique(fn (array $house) => implode(',', $house['access_point']))
                ->values();
            $evaluated = $candidates->map(fn (array $candidate) => $this->evaluate(
                $candidate['access_point'],
                $candidate['corridor_id'],
                $cluster['houses'],
                $settings->optimization_goal,
                $maxDrop,
            ))->sortBy(fn (array $candidate) => [$candidate['violations'], $candidate['score'], $candidate['point'][0], $candidate['point'][1]])->values();

            $selected = $evaluated->first();
            if ($selected['max_drop_distance_m'] > $maxDrop) {
                $warnings[] = [
                    'code' => 'odo_drop_limit_exceeded',
                    'cluster_key' => $cluster['key'],
                    'message' => "Za {$cluster['key']} nije pronađen ODO položaj unutar maksimalnih {$maxDrop} m za sve kuće.",
                    'max_distance_m' => $selected['max_drop_distance_m'],
                ];
            }

            $proposals[] = [
                'key' => 'odo-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'provisional_name' => 'ODO-P-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'cluster_key' => $cluster['key'],
                'zone_id' => $cluster['zone_id'],
                'component' => $cluster['component'],
                'point' => $selected['point'],
                'corridor_id' => $selected['corridor_id'],
                'capacity' => (int) $settings->odo_capacity,
                'occupancy' => $cluster['house_count'],
                'house_ids' => $cluster['house_ids'],
                'total_drop_length_m' => $selected['total_drop_length_m'],
                'max_drop_distance_m' => $selected['max_drop_distance_m'],
                'within_drop_limit' => $selected['max_drop_distance_m'] <= $maxDrop,
            ];
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

    private function evaluate(array $point, int $corridorId, array $houses, ?string $goal, int $maxDrop): array
    {
        $distances = array_map(fn (array $house) => $this->geometry->distanceBetweenPoints($point, $house['house_point']), $houses);
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
            'max_drop_distance_m' => (int) ceil($maximum),
            'violations' => count(array_filter($distances, fn (float $distance) => $distance > $maxDrop)),
            'score' => $score,
        ];
    }
}

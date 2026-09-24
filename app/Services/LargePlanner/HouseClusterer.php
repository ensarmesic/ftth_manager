<?php

namespace App\Services\LargePlanner;

use App\Models\House;
use App\Models\Project;
use App\Services\GeometryService;
use DomainException;

class HouseClusterer
{
    private const BATCH_SIZE = 500;

    public function __construct(private readonly GeometryService $geometry) {}

    public function cluster(Project $project, array $graph): array
    {
        $settings = $project->largePlannerSetting()->first();
        $capacity = (int) ($settings?->odo_capacity ?? 0);
        $maxDrop = (int) ($settings?->max_drop_length_m ?? 0);
        if ($capacity < 1 || $maxDrop < 1) {
            throw new DomainException('ODO kapacitet i maksimalni drop moraju biti potvrđeni prije grupisanja.');
        }

        $edges = $this->uniqueEdges($graph);
        $componentByNode = $this->componentMap($graph['components']);
        $buffers = [];
        $clusters = [];
        $unroutable = [];

        $houses = $project->houses()
            ->orderByRaw('CASE WHEN large_planner_zone_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('large_planner_zone_id')
            ->orderBy('latitude')
            ->orderBy('longitude')
            ->orderBy('id')
            ->lazy(self::BATCH_SIZE);

        foreach ($houses as $house) {
            if ($house->latitude === null || $house->longitude === null) {
                $unroutable[] = $this->unroutable($house, 'missing_coordinates');

                continue;
            }
            $attachment = $this->nearestEdge($house, $edges, $componentByNode);
            if ($attachment === null || $attachment['distance_m'] > $maxDrop) {
                $unroutable[] = $this->unroutable($house, 'outside_max_drop', $attachment['distance_m'] ?? null);

                continue;
            }
            $groupKey = ($house->large_planner_zone_id ?? 0).':'.$attachment['component'];
            $buffers[$groupKey][] = $attachment + [
                'house_id' => $house->id,
                'label' => $house->label,
                'zone_id' => $house->large_planner_zone_id,
                'house_point' => [(float) $house->latitude, (float) $house->longitude],
            ];
            if (count($buffers[$groupKey]) === $capacity) {
                $clusters[] = $this->clusterFrom($buffers[$groupKey], count($clusters));
                $buffers[$groupKey] = [];
            }
        }

        ksort($buffers);
        foreach ($buffers as $buffer) {
            if ($buffer !== []) {
                $clusters[] = $this->clusterFrom($buffer, count($clusters));
            }
        }

        return [
            'clusters' => $clusters,
            'unroutable_houses' => $unroutable,
            'summary' => [
                'houses' => $project->houses()->count(),
                'clustered_houses' => array_sum(array_column($clusters, 'house_count')),
                'clusters' => count($clusters),
                'unroutable_houses' => count($unroutable),
                'odo_capacity' => $capacity,
                'max_drop_length_m' => $maxDrop,
                'batch_size' => self::BATCH_SIZE,
            ],
        ];
    }

    private function clusterFrom(array $houses, int $index): array
    {
        $candidate = $houses[(int) floor((count($houses) - 1) / 2)];

        return [
            'key' => 'cluster-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            'zone_id' => $candidate['zone_id'],
            'component' => $candidate['component'],
            'candidate_point' => $candidate['access_point'],
            'corridor_id' => $candidate['corridor_id'],
            'house_count' => count($houses),
            'house_ids' => array_column($houses, 'house_id'),
            'max_drop_distance_m' => (int) ceil(max(array_column($houses, 'distance_m'))),
            'houses' => $houses,
        ];
    }

    private function uniqueEdges(array $graph): array
    {
        $unique = [];
        foreach ($graph['edges'] as $from => $targets) {
            foreach ($targets as $to => $edge) {
                if (strcmp($from, $to) >= 0 || $edge['corridor_id'] === null) {
                    continue;
                }
                $unique[] = $edge + ['from_key' => $from, 'to_key' => $to, 'from' => $graph['nodes'][$from], 'to' => $graph['nodes'][$to]];
            }
        }

        return $unique;
    }

    private function componentMap(array $components): array
    {
        $map = [];
        foreach ($components as $index => $nodes) {
            foreach ($nodes as $node) {
                $map[$node] = $index;
            }
        }

        return $map;
    }

    private function nearestEdge(House $house, array $edges, array $componentByNode): ?array
    {
        $best = null;
        $point = [(float) $house->latitude, (float) $house->longitude];
        foreach ($edges as $edge) {
            $projection = $this->geometry->projectPointToPath($point, [$edge['from'], $edge['to']]);
            if ($best === null || $projection['distance_m'] < $best['distance_m']) {
                $best = [
                    'distance_m' => $projection['distance_m'],
                    'access_point' => [$projection['lat'], $projection['lng']],
                    'corridor_id' => $edge['corridor_id'],
                    'corridor_type' => $edge['corridor_type'],
                    'component' => $componentByNode[$edge['from_key']] ?? 0,
                    'edge' => [$edge['from_key'], $edge['to_key']],
                ];
            }
        }

        return $best;
    }

    private function unroutable(House $house, string $reason, ?float $distance = null): array
    {
        return [
            'id' => $house->id,
            'label' => $house->label,
            'reason' => $reason,
            'distance_m' => $distance === null ? null : (int) round($distance),
        ];
    }
}

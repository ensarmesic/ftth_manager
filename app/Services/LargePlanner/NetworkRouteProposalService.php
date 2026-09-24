<?php

namespace App\Services\LargePlanner;

use App\Models\Project;
use App\Services\GeometryService;
use SplPriorityQueue;

class NetworkRouteProposalService
{
    private const ATTACHMENT_TOLERANCE_M = 2.0;

    public function __construct(private readonly GeometryService $geometry) {}

    public function propose(Project $project, array $graph, array $placement, ?array $odfPlan = null): array
    {
        $odfs = $project->odfs()->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->get();
        $sources = ($odfPlan['enabled'] ?? false) && ($odfPlan['odfs'] ?? []) !== []
            ? collect($odfPlan['odfs'])->map(fn (array $odf) => ['odf_id' => null, 'odf_key' => $odf['key'], 'point' => $odf['point']])->all()
            : $odfs->map(fn ($odf) => ['odf_id' => $odf->id, 'odf_key' => null, 'point' => [(float) $odf->latitude, (float) $odf->longitude]])->all();
        $houses = $project->houses()->get()->keyBy('id');
        $secondary = [];
        $drops = [];
        $warnings = [];

        if ($sources === []) {
            $warnings[] = ['code' => 'missing_source_odf', 'message' => 'Nije postavljen početni ODF za veliki planer.'];
        }

        foreach ($placement['odos'] as $odo) {
            $bestPath = null;
            $bestOdf = null;
            foreach ($sources as $candidate) {
                $path = $this->pathThroughGraph($graph, $candidate['point'], $odo['point']);
                if ($path !== null && ($bestPath === null || $path['length_m'] < $bestPath['length_m'])) {
                    $bestPath = $path;
                    $bestOdf = $candidate;
                }
            }

            if ($bestPath === null) {
                $warnings[] = [
                    'code' => 'odo_without_odf_route',
                    'odo_key' => $odo['key'],
                    'message' => "Za {$odo['provisional_name']} nije pronađena ruta do ODF-a kroz dozvoljene koridore.",
                ];
            } else {
                $secondary[] = [
                    'key' => 'secondary-'.$odo['key'],
                    'type' => 'secondary',
                    'odf_id' => $bestOdf['odf_id'],
                    'odf_key' => $bestOdf['odf_key'],
                    'odo_key' => $odo['key'],
                    'path' => $bestPath['path'],
                    'length_m' => $bestPath['length_m'],
                ];
            }

            foreach ($odo['house_ids'] as $houseId) {
                $house = $houses->get($houseId);
                if (! $house || $house->latitude === null || $house->longitude === null) {
                    continue;
                }
                $path = [$odo['point'], [(float) $house->latitude, (float) $house->longitude]];
                $drops[] = [
                    'key' => 'drop-'.$odo['key'].'-'.$house->id,
                    'type' => 'drop',
                    'odo_key' => $odo['key'],
                    'house_id' => $house->id,
                    'path' => $path,
                    'length_m' => $this->geometry->polylineLength($path),
                ];
            }
        }

        return [
            'secondary_routes' => $secondary,
            'drop_routes' => $drops,
            'warnings' => $warnings,
            'summary' => [
                'secondary_routes' => count($secondary),
                'drop_routes' => count($drops),
                'secondary_length_m' => array_sum(array_column($secondary, 'length_m')),
                'drop_length_m' => array_sum(array_column($drops, 'length_m')),
                'warnings' => count($warnings),
            ],
        ];
    }

    public function pathThroughGraph(array $graph, array $from, array $to): ?array
    {
        $start = $this->attachment($graph, $from);
        $end = $this->attachment($graph, $to);
        if ($start === null || $end === null
            || $start['distance_m'] > self::ATTACHMENT_TOLERANCE_M
            || $end['distance_m'] > self::ATTACHMENT_TOLERANCE_M
            || $start['component'] !== $end['component']) {
            return null;
        }
        if ($start['edge'] === $end['edge']) {
            $path = $this->geometry->compactPath([$start['projection'], $end['projection']]);

            return ['path' => $path, 'length_m' => $this->geometry->polylineLength($path)];
        }

        $distances = [];
        $previous = [];
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        foreach ($start['endpoint_weights'] as $node => $weight) {
            $distances[$node] = $weight;
            $queue->insert($node, -$weight);
        }
        while (! $queue->isEmpty()) {
            $entry = $queue->extract();
            $node = (string) $entry['data'];
            $distance = -(float) $entry['priority'];
            if ($distance > ($distances[$node] ?? INF)) {
                continue;
            }
            foreach ($graph['edges'][$node] ?? [] as $next => $edge) {
                $candidate = $distance + $edge['weight_m'];
                if ($candidate < ($distances[$next] ?? INF)) {
                    $distances[$next] = $candidate;
                    $previous[$next] = $node;
                    $queue->insert($next, -$candidate);
                }
            }
        }

        $targetNode = collect($end['endpoint_weights'])->keys()
            ->filter(fn (string $node) => isset($distances[$node]))
            ->sortBy(fn (string $node) => $distances[$node] + $end['endpoint_weights'][$node])
            ->first();
        if ($targetNode === null) {
            return null;
        }

        $nodePath = [$targetNode];
        while (isset($previous[$nodePath[0]])) {
            array_unshift($nodePath, $previous[$nodePath[0]]);
        }
        $path = [$start['projection']];
        foreach ($nodePath as $node) {
            $path[] = $graph['nodes'][$node];
        }
        $path[] = $end['projection'];
        $path = $this->geometry->compactPath($path);

        return ['path' => $path, 'length_m' => $this->geometry->polylineLength($path)];
    }

    private function attachment(array $graph, array $point): ?array
    {
        $best = null;
        $componentMap = [];
        foreach ($graph['components'] as $component => $nodes) {
            foreach ($nodes as $node) {
                $componentMap[$node] = $component;
            }
        }
        foreach ($graph['edges'] as $from => $targets) {
            foreach ($targets as $to => $edge) {
                if (strcmp($from, $to) >= 0 || $edge['corridor_id'] === null) {
                    continue;
                }
                $projection = $this->geometry->projectPointToPath($point, [$graph['nodes'][$from], $graph['nodes'][$to]]);
                if ($best === null || $projection['distance_m'] < $best['distance_m']) {
                    $projected = [$projection['lat'], $projection['lng']];
                    $best = [
                        'distance_m' => $projection['distance_m'],
                        'projection' => $projected,
                        'component' => $componentMap[$from] ?? 0,
                        'edge' => [$from, $to],
                        'endpoint_weights' => [
                            $from => $this->geometry->distanceBetweenPoints($projected, $graph['nodes'][$from]),
                            $to => $this->geometry->distanceBetweenPoints($projected, $graph['nodes'][$to]),
                        ],
                    ];
                }
            }
        }

        return $best;
    }
}

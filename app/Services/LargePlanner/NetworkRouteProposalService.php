<?php

namespace App\Services\LargePlanner;

use App\Models\Project;
use App\Services\GeometryService;
use SplPriorityQueue;

class NetworkRouteProposalService
{
    private const ATTACHMENT_TOLERANCE_M = 2.0;

    private const NORMAL_MAX_ODOS_PER_BRANCH = 2;

    private const EXTREME_MAX_ODOS_PER_BRANCH = 3;

    private const THREE_ODO_MIN_SAVED_CABLE_M = 300.0;

    public function __construct(private readonly GeometryService $geometry) {}

    public function propose(Project $project, array $graph, array $placement, ?array $odfPlan = null): array
    {
        $odfs = $project->odfs()->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->get();
        $manualOdfs = $odfs->whereNull('import_batch')->values();
        if ($manualOdfs->isNotEmpty()) {
            $odfs = $manualOdfs;
        }
        $sources = ($odfPlan['enabled'] ?? false) && ($odfPlan['odfs'] ?? []) !== []
            ? collect($odfPlan['odfs'])->map(fn (array $odf) => ['odf_id' => null, 'odf_key' => $odf['key'], 'point' => $odf['point'], 'odo_keys' => $odf['odo_keys'] ?? []])->all()
            : $odfs->map(fn ($odf) => ['odf_id' => $odf->id, 'odf_key' => null, 'point' => [(float) $odf->latitude, (float) $odf->longitude]])->all();
        $houses = $project->houses()->get()->keyBy('id');
        $requiredWaypoints = $project->largePlannerConstraints()
            ->where('type', 'required_waypoint')
            ->orderBy('id')
            ->pluck('geometry')
            ->filter(fn ($point) => is_array($point) && count($point) === 2)
            ->map(fn (array $point) => [(float) $point[0], (float) $point[1]])
            ->values()->all();
        $secondary = [];
        $drops = [];
        $warnings = [];

        if ($sources === []) {
            $warnings[] = ['code' => 'missing_source_odf', 'message' => 'Nije postavljen početni ODF za veliki planer.'];
        }

        foreach ($placement['odos'] as $odo) {
            $bestPath = null;
            $bestOdf = null;
            $assignedSources = collect($sources)->filter(fn (array $source) => in_array($odo['key'], $source['odo_keys'] ?? [], true))->values()->all();
            $candidateSources = $assignedSources !== [] ? $assignedSources : $sources;
            foreach ($candidateSources as $candidate) {
                $path = $this->pathThroughRequiredWaypoints($graph, $candidate['point'], $odo['point'], $requiredWaypoints);
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
                $secondaryPath = $bestPath['path'];
                if (count($secondaryPath) < 2) {
                    // Predloženi ODF i prvi ODO mogu dijeliti istu tačku na rovu.
                    // I dalje sačuvaj oba logička kraja servisne veze.
                    $secondaryPath = [$bestOdf['point'], $odo['point']];
                }
                $secondary[] = [
                    'key' => 'secondary-'.$odo['key'],
                    'type' => 'secondary',
                    'odf_id' => $bestOdf['odf_id'],
                    'odf_key' => $bestOdf['odf_key'],
                    'odo_key' => $odo['key'],
                    'path' => $secondaryPath,
                    'length_m' => $bestPath['length_m'],
                ];
            }

            foreach ($odo['house_ids'] as $houseId) {
                $house = $houses->get($houseId);
                if (! $house || $house->latitude === null || $house->longitude === null) {
                    continue;
                }
                $housePoint = [(float) $house->latitude, (float) $house->longitude];
                $attachment = $this->attachment($graph, $housePoint);
                $corridorPath = $attachment === null
                    ? null
                    : $this->pathThroughGraph($graph, $odo['point'], $attachment['projection']);
                if ($corridorPath === null) {
                    $warnings[] = [
                        'code' => 'house_without_corridor_route',
                        'odo_key' => $odo['key'],
                        'house_id' => $house->id,
                        'message' => "Za kuću {$house->label} nije pronađena ruta od ODO-a kroz glavni rov.",
                    ];

                    continue;
                }
                $path = $this->geometry->compactPath([...$corridorPath['path'], $housePoint]);
                if (count($path) < 2) {
                    // Kuća može biti praktično na tački ODO-a. Zadrži oba
                    // kraja servisnog spoja i kada su koordinate jednake.
                    $path = [$odo['point'], $housePoint];
                }
                $drops[] = [
                    'key' => 'drop-'.$odo['key'].'-'.$house->id,
                    'type' => 'drop',
                    'odo_key' => $odo['key'],
                    'house_id' => $house->id,
                    'path' => $path,
                    'length_m' => $this->geometry->polylineLength($path),
                    'corridor_length_m' => $corridorPath['length_m'],
                    'house_connection_length_m' => $attachment['distance_m'],
                ];
            }
        }

        [$secondary, $namedOdos] = $this->secondaryBranches($secondary, $placement['odos']);
        [$secondary, $namedOdos] = $this->attachSingletonBranches($graph, $secondary, $namedOdos);
        foreach ($secondary as $branch) {
            if (count($branch['odo_keys'] ?? []) === 1 && ! ($branch['is_lateral'] ?? false)) {
                $warnings[] = [
                    'code' => 'single_odo_branch',
                    'route_key' => $branch['key'],
                    'odo_key' => $branch['odo_keys'][0],
                    'message' => "{$branch['name']} ima samo jedan ODO; provjeri da li je fizički izdvojen od ostalih krakova.",
                ];
            }
        }

        return [
            'secondary_routes' => $secondary,
            'drop_routes' => $drops,
            'odos' => $namedOdos,
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

    private function secondaryBranches(array $routes, array $odos): array
    {
        $odoByKey = collect($odos)->keyBy('key');
        $named = $odoByKey;
        $chains = [];
        foreach (collect($routes)->groupBy(fn (array $route) => $route['odf_id'] ?? 'new-'.$route['odf_key'])->sortKeys() as $sourceRoutes) {
            $remaining = $sourceRoutes->sortByDesc('length_m')->values();
            while ($remaining->isNotEmpty()) {
                $terminal = $remaining->shift();
                $samePath = $remaining->filter(function (array $route) use ($terminal, $odoByKey): bool {
                    $point = $odoByKey->get($route['odo_key'])['point'] ?? null;

                    return is_array($point)
                        && $this->geometry->distanceToRoute((float) $point[0], (float) $point[1], $terminal['path']) <= 3.0;
                });
                $chain = $samePath->push($terminal)->sortBy(fn (array $route) => [$this->routePosition($odoByKey->get($route['odo_key'])['point'], $terminal['path']), $route['odo_key']])->values();
                $remaining = $remaining->reject(fn (array $route) => $samePath->contains(fn (array $item) => $item['odo_key'] === $route['odo_key']))->values();
                foreach ($this->branchChunks($chain, (float) $terminal['length_m']) as $chunk) {
                    $chains[] = $chunk;
                }
            }
        }

        $branches = collect($chains)
            ->map(function ($ordered, int $branchIndex) use (&$named): array {
                $terminal = $ordered->last();
                $branchNumber = $branchIndex + 1;
                $odoKeys = $ordered->pluck('odo_key')->all();
                foreach ($odoKeys as $order => $odoKey) {
                    $odo = $named->get($odoKey);
                    $odo['provisional_name'] = 'ZO-'.$branchNumber.'.'.($order + 1);
                    $odo['odf_id'] = $terminal['odf_id'];
                    $odo['odf_key'] = $terminal['odf_key'];
                    $odo['secondary_branch_key'] = 'secondary-branch-'.str_pad((string) $branchNumber, 4, '0', STR_PAD_LEFT);
                    $odo['secondary_branch_name'] = 'Sekundarni krak '.$branchNumber;
                    $odo['branch_index'] = $branchNumber;
                    $odo['branch_order'] = $order + 1;
                    $named->put($odoKey, $odo);
                }

                return [
                    'key' => 'secondary-branch-'.str_pad((string) $branchNumber, 4, '0', STR_PAD_LEFT),
                    'name' => 'Sekundarni krak '.$branchNumber,
                    'type' => 'secondary',
                    'branch_index' => $branchNumber,
                    'odf_id' => $terminal['odf_id'],
                    'odf_key' => $terminal['odf_key'],
                    'odo_keys' => $odoKeys,
                    'odo_sequence' => $odoKeys,
                    'terminal_odo_key' => $terminal['odo_key'],
                    'path' => $terminal['path'],
                    'length_m' => $terminal['length_m'],
                ];
            })->all();

        return [$branches, $named->values()->all()];
    }

    private function branchChunks($chain, float $sharedRouteLength): array
    {
        $chunks = [];
        $remaining = $chain->values();
        $useExtremeGroup = $remaining->count() > self::NORMAL_MAX_ODOS_PER_BRANCH
            && $remaining->count() % self::NORMAL_MAX_ODOS_PER_BRANCH === 1
            && $sharedRouteLength >= self::THREE_ODO_MIN_SAVED_CABLE_M;

        while ($remaining->isNotEmpty()) {
            $size = self::NORMAL_MAX_ODOS_PER_BRANCH;
            if ($useExtremeGroup && $remaining->count() === self::EXTREME_MAX_ODOS_PER_BRANCH) {
                $size = self::EXTREME_MAX_ODOS_PER_BRANCH;
            }
            $chunks[] = $remaining->splice(0, $size)->values();
        }

        return $chunks;
    }

    private function attachSingletonBranches(array $graph, array $branches, array $odos): array
    {
        $odoByKey = collect($odos)->keyBy('key');
        $mainBranches = collect($branches)
            ->filter(fn (array $branch) => count($branch['odo_keys'] ?? []) >= 2)
            ->values()
            ->map(function (array $branch, int $index) use (&$odoByKey): array {
                $number = $index + 1;
                $branch['name'] = 'Sekundarni krak '.$number;
                $branch['branch_index'] = $number;
                $branch['branch_number'] = (string) $number;
                foreach ($branch['odo_keys'] as $order => $odoKey) {
                    $odo = $odoByKey->get($odoKey);
                    $odo['provisional_name'] = 'ZO-'.$number.'.'.($order + 1);
                    $odo['secondary_branch_name'] = $branch['name'];
                    $odo['branch_index'] = $number;
                    $odo['branch_number'] = (string) $number;
                    $odoByKey->put($odoKey, $odo);
                }

                return $branch;
            });
        $mainByKey = $mainBranches->keyBy('key');
        $lateralCounters = [];

        $updated = collect($branches)->map(function (array $branch) use ($graph, $mainBranches, $mainByKey, &$odoByKey, &$lateralCounters): array {
            if (count($branch['odo_keys'] ?? []) !== 1) {
                return $mainByKey->get($branch['key'], $branch);
            }
            $singleKey = $branch['odo_keys'][0];
            $singlePoint = $odoByKey->get($singleKey)['point'] ?? null;
            if (! is_array($singlePoint)) {
                return $branch;
            }

            $candidates = $mainBranches
                ->filter(fn (array $parent) => ($parent['odf_key'] ?? null) === ($branch['odf_key'] ?? null)
                    && (int) ($parent['odf_id'] ?? 0) === (int) ($branch['odf_id'] ?? 0))
                ->flatMap(fn (array $parent) => collect($parent['odo_keys'])->map(fn (string $odoKey) => [
                    'branch' => $parent,
                    'odo_key' => $odoKey,
                    'point' => $odoByKey->get($odoKey)['point'] ?? null,
                ]))
                ->filter(fn (array $candidate) => is_array($candidate['point']))
                ->map(function (array $candidate) use ($graph, $singlePoint): array {
                    $candidate['route'] = $this->pathThroughGraph($graph, $candidate['point'], $singlePoint);

                    return $candidate;
                })
                ->filter(fn (array $candidate) => $candidate['route'] !== null)
                ->sortBy(fn (array $candidate) => [$candidate['route']['length_m'], $candidate['branch']['branch_index'], $candidate['odo_key']]);
            $parent = $candidates->first();
            if ($parent === null) {
                return $branch;
            }

            $parentNumber = (int) $parent['branch']['branch_index'];
            $lateralCounters[$parentNumber] = ($lateralCounters[$parentNumber] ?? 0) + 1;
            $lateralNumber = $parentNumber.'.'.$lateralCounters[$parentNumber];
            $odo = $odoByKey->get($singleKey);
            $odo['provisional_name'] = 'ZO-'.$lateralNumber.'.1';
            $odo['secondary_branch_key'] = $branch['key'];
            $odo['secondary_branch_name'] = 'Sekundarni krak '.$lateralNumber;
            $odo['parent_odo_key'] = $parent['odo_key'];
            $odo['branch_number'] = $lateralNumber;
            $odoByKey->put($singleKey, $odo);

            return array_merge($branch, [
                'name' => 'Sekundarni krak '.$lateralNumber,
                'branch_number' => $lateralNumber,
                'is_lateral' => true,
                'parent_branch_key' => $parent['branch']['key'],
                'from_odo_key' => $parent['odo_key'],
                'path' => $parent['route']['path'],
                'length_m' => $parent['route']['length_m'],
            ]);
        });

        return [$updated->values()->all(), $odoByKey->values()->all()];
    }

    private function routePosition(array $point, array $path): float
    {
        $chainage = 0.0;
        $best = INF;
        $bestChainage = INF;
        for ($index = 1; $index < count($path); $index++) {
            $segment = [$path[$index - 1], $path[$index]];
            $projection = $this->geometry->projectPointToPath($point, $segment);
            if ($projection['distance_m'] < $best) {
                $best = $projection['distance_m'];
                $bestChainage = $chainage + $this->geometry->distanceBetweenPoints($path[$index - 1], [$projection['lat'], $projection['lng']]);
            }
            $chainage += $this->geometry->distanceBetweenPoints($path[$index - 1], $path[$index]);
        }

        return $bestChainage;
    }

    private function pathThroughRequiredWaypoints(array $graph, array $from, array $to, array $waypoints): ?array
    {
        $stops = [...$waypoints, $to];
        $current = $from;
        $path = [];
        $length = 0.0;
        foreach ($stops as $stop) {
            $segment = $this->pathThroughGraph($graph, $current, $stop);
            if ($segment === null) {
                return null;
            }
            $segmentPath = $segment['path'];
            if ($path !== [] && $segmentPath !== []) {
                array_shift($segmentPath);
            }
            $path = [...$path, ...$segmentPath];
            $length += $segment['length_m'];
            $current = $stop;
        }

        return ['path' => $this->geometry->compactPath($path), 'length_m' => $length];
    }

    public function pathThroughGraph(array $graph, array $from, array $to, ?array $allowedCorridorTypes = null): ?array
    {
        $start = $this->attachment($graph, $from, $allowedCorridorTypes);
        $end = $this->attachment($graph, $to, $allowedCorridorTypes);
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
                if ($allowedCorridorTypes !== null && $edge['corridor_id'] !== null && ! in_array($edge['corridor_type'], $allowedCorridorTypes, true)) {
                    continue;
                }
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

    private function attachment(array $graph, array $point, ?array $allowedCorridorTypes = null): ?array
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
                if ($allowedCorridorTypes !== null && ! in_array($edge['corridor_type'], $allowedCorridorTypes, true)) {
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

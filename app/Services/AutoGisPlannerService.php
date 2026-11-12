<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class AutoGisPlannerService
{
    private const HOUSES_PER_CABINET = 12;

    private const AERIAL_HOUSES_PER_CABINET = 4;

    private const MAX_DROP_M = 120;

    public function __construct(
        private readonly RouteGraphService $graph,
        private readonly BranchSyncService $branches,
    ) {}

    public function preview(Project $project, int $limit = 80): array
    {
        $odfs = $project->odfs()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->get();

        if ($odfs->isEmpty()) {
            return [
                'summary' => ['status' => 'missing_odf', 'message' => 'Projekt nema ODF sa koordinatama.'],
                'routes' => [],
                'unrouted_houses' => [],
            ];
        }

        // Only plan houses that are not yet on an ODO, so re-running the GIS plan
        // is incremental (like Auto ODO) instead of cloning the whole network.
        $houses = $project->houses()
            ->whereNull('cabinet_id')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $routes = [];
        $unrouted = [];
        $totalLength = 0;
        $source = null;
        $network = [];
        // Build the routing graph once; every ODF and cabinet search reuses it.
        $routing = $this->graph->network($project->id);
        $assignments = $this->capacityAwareAssignments($routing, $odfs, $houses);

        foreach ($assignments as $assignment) {
            $house = $assignment['house'];
            $best = $assignment['best'];
            $route = $best['route'] ?? null;
            $odf = $best['odf'] ?? null;

            if (! $route) {
                $unrouted[] = $this->housePayload($house);

                continue;
            }

            $source ??= $route['graph']['source'] ?? null;
            $totalLength += (int) $route['length_m'];
            $this->addRouteToNetwork($network, $route['path'], $house);
            $routes[] = [
                'name' => 'GIS krak '.$house->label,
                'house' => $this->housePayload($house),
                'odf' => ['id' => $odf->id, 'name' => $odf->name, 'lat' => (float) $odf->latitude, 'lng' => (float) $odf->longitude],
                'capacity_forced' => (bool) ($assignment['capacity_forced'] ?? false),
                'length_m' => (int) $route['length_m'],
                'path' => $route['path'],
                'access_point' => $route['access_point'] ?? null,
                'graph' => $route['graph'],
            ];
        }

        $networkSegments = $this->networkSegments($network);
        $networkLength = array_sum(array_column($networkSegments, 'length_m'));
        // Use the drop limit the engineer set for this project, when there is one.
        $maxDrop = (int) ($project->largePlannerSetting?->max_drop_length_m ?: self::MAX_DROP_M);
        $cabinets = $this->cabinetGroups($project, $routes, $routing, $maxDrop);
        $odfSummary = $this->odfSummary($routes, $cabinets, $odfs);
        $quality = $this->qualitySummary($routes, $cabinets, $odfSummary, count($unrouted), $maxDrop);

        return [
            'summary' => [
                'status' => count($routes) ? 'ok' : 'no_routes',
                'project_id' => $project->id,
                'odf' => $odfSummary[0] ?? null,
                'odf_count' => $odfs->count(),
                'used_odf_count' => count($odfSummary),
                'houses_total' => $houses->count(),
                'routed_houses' => count($routes),
                'unrouted_houses' => count($unrouted),
                'total_route_m' => $totalLength,
                'unique_network_m' => $networkLength,
                'shared_savings_m' => max($totalLength - $networkLength, 0),
                'average_route_m' => count($routes) ? (int) round($totalLength / count($routes)) : 0,
                'network_segment_count' => count($networkSegments),
                'cabinet_count' => count($cabinets),
                'average_drop_m' => $quality['average_drop_m'],
                'max_drop_m' => $quality['max_drop_m'],
                'average_utilization_percent' => $quality['average_utilization_percent'],
                'score' => $quality['score'],
                'graph_source' => $source,
            ],
            'odfs' => $odfSummary,
            'warnings' => $quality['warnings'],
            'routes' => $routes,
            'network_segments' => $networkSegments,
            'cabinets' => $cabinets,
            'unrouted_houses' => $unrouted,
        ];
    }

    public function confirm(Project $project, int $limit = 80): array
    {
        $plan = $this->preview($project, $limit);
        $segments = collect($plan['network_segments'] ?? [])
            ->filter(fn (array $segment) => count($segment['path'] ?? []) >= 2)
            ->values();

        if ($segments->isEmpty()) {
            throw new \InvalidArgumentException('GIS plan nema segmenata za snimanje.');
        }

        $created = collect();
        $createdCabinets = collect();

        DB::transaction(function () use ($project, $segments, $plan, $created, $createdCabinets): void {
            foreach ($segments as $index => $segment) {
                $name = $this->uniqueRouteName($project, 'GIS krak '.($index + 1));
                $route = NetworkRoute::create([
                    'project_id' => $project->id,
                    'name' => $name,
                    'route_type' => 'distribution',
                    'installation_type' => $project->isAerial() ? 'aerial' : 'underground',
                    'duct_length_m' => (int) $segment['length_m'],
                    'fiber_length_m' => (int) $segment['length_m'],
                    'fiber_count' => $this->fiberCountForLoad((int) $segment['house_count']),
                    'microduct_count' => $project->isAerial() ? 0 : 1,
                    'microduct_type' => $project->isAerial() ? null : '14/10',
                    'cable_type' => $project->isAerial() ? 'ADSS' : null,
                    'status' => 'planned',
                    'path' => $segment['path'],
                    'note' => 'Automatski kreirano iz GIS plana. Korisnika: '.(int) $segment['house_count'],
                ]);
                $this->branches->createBranchForRoute($route);
                $created->push($route);
            }

            foreach (($plan['cabinets'] ?? []) as $index => $cabinetPlan) {
                $cabinet = Cabinet::create([
                    'project_id' => $project->id,
                    'odf_id' => $cabinetPlan['odf']['id'] ?? ($plan['summary']['odf']['id'] ?? null),
                    'name' => $this->uniqueCabinetName($project, ($project->isAerial() ? 'GIS ODP ' : 'GIS ODO ').($index + 1)),
                    'address' => 'GIS plan',
                    'splitter_count' => $project->isAerial() ? 1 : $this->splitterCount(count($cabinetPlan['houses'] ?? [])),
                    'ports_per_splitter' => 4,
                    'latitude' => $cabinetPlan['lat'],
                    'longitude' => $cabinetPlan['lng'],
                ]);
                $createdCabinets->push($cabinet);

                foreach (($cabinetPlan['houses'] ?? []) as $housePayload) {
                    $house = House::where('project_id', $project->id)->find($housePayload['id']);
                    if (! $house) {
                        continue;
                    }
                    $house->update(['cabinet_id' => $cabinet->id]);
                }
            }
        });

        return [
            'created' => $created->count(),
            'created_cabinets' => $createdCabinets->count(),
            'routes' => $created->map(fn (NetworkRoute $route) => [
                'id' => $route->id,
                'name' => $route->name,
                'type' => $route->route_type,
                'length' => $route->duct_length_m,
                'duct_length_m' => $route->duct_length_m,
                'fiber_count' => $route->fiber_count,
                'fibers' => $route->fiber_count,
                'microduct_type' => $route->microduct_type,
                'microduct_count' => $route->microduct_count,
                'installation_type' => $route->installation_type,
                'path' => $route->path,
                'status' => $route->status,
                'note' => $route->note,
                'project_id' => $route->project_id,
            ])->values(),
            'cabinets' => $createdCabinets->map(fn (Cabinet $cabinet) => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
                'lat' => (float) $cabinet->latitude,
                'lng' => (float) $cabinet->longitude,
                'splitter_count' => $cabinet->splitter_count,
                'capacity' => $cabinet->capacity,
                'used_ports' => $cabinet->houses()->count(),
            ])->values(),
            'summary' => $plan['summary'],
        ];
    }

    /**
     * Group houses into cabinets by where they sit on the network, not by how
     * far they are from the ODF: houses that share the same stretch of route
     * end up together, and the cabinet goes on the corridor between them.
     */
    private function cabinetGroups(Project $project, array $routes, ?array $routing, int $maxDrop): array
    {
        $capacity = $project->isAerial() ? self::AERIAL_HOUSES_PER_CABINET : self::HOUSES_PER_CABINET;
        $cabinets = [];

        foreach (collect($routes)->groupBy(fn (array $route) => $route['odf']['id'] ?? 0) as $odfRoutes) {
            $odfRoutes = $odfRoutes->values()->all();
            $tree = $this->routeTree($odfRoutes);
            $placed = [];
            foreach ($this->houseGroups($tree, $capacity, $maxDrop) as $group) {
                $placed[] = ['node' => $this->bestCabinet($tree, $group)['node'], 'routes' => $group];
            }
            // Number the cabinets outward from the ODF.
            usort($placed, fn (array $a, array $b) => $tree['depth'][$a['node']] <=> $tree['depth'][$b['node']]);

            foreach ($placed as $item) {
                $cabinets[] = $this->cabinetPayload(
                    $project,
                    $routing,
                    $tree['points'][$item['node']],
                    array_map(fn (int $index) => $odfRoutes[$index], $item['routes']),
                    count($cabinets),
                );
            }
        }

        return $cabinets;
    }

    /**
     * The routes from one ODF fan out as a tree. Record each point's parent
     * and distance from the ODF, and hang every house on the corridor point
     * where its route leaves the road.
     */
    private function routeTree(array $routes): array
    {
        $tree = ['points' => [], 'parent' => [], 'depth' => [], 'hops' => [], 'houses' => [], 'access' => [], 'spur' => []];

        foreach ($routes as $index => $route) {
            $previous = null;
            foreach ($route['path'] as $point) {
                $point = $this->normalizedPoint($point);
                $key = $this->pointKey($point);
                if (! isset($tree['points'][$key])) {
                    $tree['points'][$key] = $point;
                    $tree['parent'][$key] = $previous;
                    $tree['depth'][$key] = $previous === null ? 0.0 : $tree['depth'][$previous] + $this->meters($tree['points'][$previous], $point);
                    $tree['hops'][$key] = $previous === null ? 0 : $tree['hops'][$previous] + 1;
                }
                $previous = $key;
            }
            if ($previous === null) {
                continue;
            }

            $access = is_array($route['access_point'] ?? null) ? $this->pointKey($this->normalizedPoint($route['access_point'])) : $previous;
            if (! isset($tree['points'][$access])) {
                $access = $previous;
            }
            $spur = $this->meters($tree['points'][$access], [$route['house']['lat'], $route['house']['lng']]);
            $tree['access'][$index] = $access;
            $tree['spur'][$index] = $spur;
            $tree['houses'][$access][] = [$index, $tree['depth'][$access] + $spur];
        }

        return $tree;
    }

    /**
     * Walk the tree from its far ends toward the ODF, collecting houses. A
     * group closes when it is full, or when joining it with the houses from
     * another street would leave no cabinet position that keeps every drop
     * within the limit.
     *
     * @return array<int, array<int, int>> route indexes per group
     */
    private function houseGroups(array $tree, int $capacity, int $maxDrop): array
    {
        $total = count($tree['access']);
        $windows = (int) ceil($total / max($capacity, 1));
        // Split evenly (e.g. 11 + 11 instead of 12 + 10) only while every
        // cabinet stays close to full.
        $size = $windows > 1 && intdiv($total, $windows) >= max(1, $capacity - 2)
            ? (int) ceil($total / $windows)
            : $capacity;

        $hops = $tree['hops'];
        arsort($hops);
        $pending = [];
        $groups = [];
        $farthestFirst = fn (array $a, array $b) => $b[1] <=> $a[1];

        foreach (array_keys($hops) as $key) {
            $key = (string) $key;
            $arriving = $pending[$key] ?? [];
            $own = $tree['houses'][$key] ?? [];
            unset($pending[$key]);
            if ($arriving === [] && $own === []) {
                continue;
            }

            if ($own === [] && count($arriving) === 1) {
                // One group just passing through: nothing about it changes.
                $list = $arriving[0];
            } else {
                $list = $own;
                // Join the nearest groups first.
                usort($arriving, fn (array $a, array $b) => $a[0][1] <=> $b[0][1]);
                foreach ($arriving as $group) {
                    $joined = array_merge($list, $group);
                    if ($list === [] || $this->bestCabinet($tree, array_column($joined, 0))['longest'] <= $maxDrop) {
                        $list = $joined;
                    } else {
                        $groups[] = array_column($group, 0);
                    }
                }
                // A full group takes the far end; the houses nearest this
                // point carry on toward the ODF.
                usort($list, $farthestFirst);
                while (count($list) >= $size) {
                    $groups[] = array_column(array_splice($list, 0, $size), 0);
                }
            }

            if ($list === []) {
                continue;
            }
            $parent = $tree['parent'][$key];
            if ($parent === null) {
                $groups[] = array_column($list, 0);
            } else {
                $pending[$parent][] = $list;
            }
        }

        return $groups;
    }

    /**
     * The corridor point that keeps the group's drops shortest. On a tree the
     * best spot is always where a house joins the road or where two of their
     * routes meet, so only those points need checking.
     *
     * @return array{node:string,longest:float,total:float}
     */
    private function bestCabinet(array $tree, array $group): array
    {
        $accesses = array_values(array_unique(array_map(fn (int $index) => $tree['access'][$index], $group)));
        $candidates = array_fill_keys($accesses, true);
        for ($i = 0; $i < count($accesses); $i++) {
            for ($j = $i + 1; $j < count($accesses); $j++) {
                $meeting = $this->meetingNode($tree, $accesses[$i], $accesses[$j]);
                if ($meeting !== null) {
                    $candidates[$meeting] = true;
                }
            }
        }

        $best = ['node' => $accesses[0], 'longest' => INF, 'total' => INF];
        foreach (array_keys($candidates) as $candidate) {
            $candidate = (string) $candidate;
            $distanceTo = [];
            foreach ($accesses as $access) {
                $meeting = $this->meetingNode($tree, $candidate, $access);
                $distanceTo[$access] = $tree['depth'][$candidate] + $tree['depth'][$access]
                    - (2 * ($meeting === null ? 0.0 : $tree['depth'][$meeting]));
            }
            $total = 0.0;
            $longest = 0.0;
            foreach ($group as $index) {
                $drop = $distanceTo[$tree['access'][$index]] + $tree['spur'][$index];
                $total += $drop;
                $longest = max($longest, $drop);
            }
            if ($total + $longest < $best['total'] + $best['longest']) {
                $best = ['node' => $candidate, 'longest' => $longest, 'total' => $total];
            }
        }

        return $best;
    }

    /**
     * The point where the routes to two tree nodes split apart.
     */
    private function meetingNode(array $tree, string $a, string $b): ?string
    {
        while ($a !== $b) {
            // Step the deeper of the two one point closer to the ODF.
            if ($tree['hops'][$a] >= $tree['hops'][$b]) {
                $a = $tree['parent'][$a];
            } else {
                $b = $tree['parent'][$b];
            }
            if ($a === null || $b === null) {
                return null;
            }
        }

        return $a;
    }

    private function cabinetPayload(Project $project, ?array $routing, array $point, array $items, int $index): array
    {
        $houses = array_column($items, 'house');
        $targets = [];
        foreach ($houses as $house) {
            $targets[$house['id']] = [$house['lat'], $house['lng']];
        }
        $drops = $routing ? $this->graph->routesFrom($routing, $point, $targets) : [];

        $dropPreviews = array_map(function (array $house) use ($drops, $point): array {
            $housePoint = [$house['lat'], $house['lng']];
            $route = $drops[$house['id']] ?? null;
            $path = $route['path'] ?? [];
            if (count($path) < 2) {
                $path = [$point, $housePoint];
            }

            return [
                'house_id' => $house['id'],
                'house_label' => $house['label'],
                'length_m' => (int) ($route['length_m'] ?? $this->distance($point, $housePoint)),
                'path' => $path,
            ];
        }, $houses);

        $count = count($items);
        $capacity = $project->isAerial() ? self::AERIAL_HOUSES_PER_CABINET : $this->splitterCount($count) * 4;

        return [
            'name' => ($project->isAerial() ? 'GIS ODP ' : 'GIS ODO ').($index + 1),
            'odf' => $items[0]['odf'],
            'lat' => (float) $point[0],
            'lng' => (float) $point[1],
            'house_count' => $count,
            'splitter_count' => $project->isAerial() ? 1 : $this->splitterCount($count),
            'capacity' => $capacity,
            'utilization_percent' => (int) round($count / max($capacity, 1) * 100),
            'max_drop_m' => max(array_column($dropPreviews, 'length_m') ?: [0]),
            'average_drop_m' => count($dropPreviews) ? (int) round(array_sum(array_column($dropPreviews, 'length_m')) / count($dropPreviews)) : 0,
            'houses' => $houses,
            'drop_preview' => $dropPreviews,
        ];
    }

    private function capacityAwareAssignments(?array $routing, $odfs, $houses): array
    {
        $remainingSlots = $this->odfHouseSlots($odfs);
        $targets = $houses
            ->mapWithKeys(fn (House $house) => [$house->id => [(float) $house->latitude, (float) $house->longitude]])
            ->all();

        // One search per ODF reaches every house.
        $candidates = [];
        foreach ($odfs as $odf) {
            $found = $routing
                ? $this->graph->routesFrom($routing, [(float) $odf->latitude, (float) $odf->longitude], $targets)
                : [];
            foreach ($found as $houseId => $route) {
                if ($route) {
                    $candidates[$houseId][] = ['odf' => $odf, 'route' => $route, 'length_m' => (int) $route['length_m']];
                }
            }
        }

        $candidatesByHouse = $houses->map(fn (House $house) => [
            'house' => $house,
            'candidates' => collect($candidates[$house->id] ?? [])->sortBy('length_m')->values()->all(),
        ]);

        return $candidatesByHouse
            ->sortBy(fn (array $item) => $item['candidates'][0]['length_m'] ?? PHP_INT_MAX)
            ->map(function (array $item) use (&$remainingSlots): array {
                $best = null;
                $capacityForced = false;

                foreach ($item['candidates'] as $candidate) {
                    $odfId = (int) $candidate['odf']->id;
                    if (($remainingSlots[$odfId] ?? PHP_INT_MAX) > 0) {
                        $best = $candidate;
                        $remainingSlots[$odfId] = ($remainingSlots[$odfId] ?? PHP_INT_MAX) === PHP_INT_MAX
                            ? PHP_INT_MAX
                            : $remainingSlots[$odfId] - 1;
                        $capacityForced = $candidate !== ($item['candidates'][0] ?? null);
                        break;
                    }
                }

                $best ??= $item['candidates'][0] ?? null;

                return [
                    'house' => $item['house'],
                    'best' => $best,
                    'capacity_forced' => $capacityForced,
                ];
            })
            ->all();
    }

    private function odfHouseSlots($odfs): array
    {
        return $odfs->mapWithKeys(function ($odf): array {
            $usedSplitters = (int) $odf->cabinets()->sum('splitter_count');
            $remainingSplitters = max((int) $odf->fiber_capacity - $usedSplitters, 0);
            $slots = (int) $odf->fiber_capacity > 0 ? $remainingSplitters * 4 : PHP_INT_MAX;

            return [$odf->id => $slots];
        })->all();
    }

    private function odfSummary(array $routes, array $cabinets, $odfs): array
    {
        $cabinetsByOdf = collect($cabinets)->groupBy(fn (array $cabinet) => $cabinet['odf']['id'] ?? 0);
        $odfsById = $odfs->keyBy('id');

        return collect($routes)
            ->groupBy(fn (array $route) => $route['odf']['id'] ?? 0)
            ->map(function ($items, int $odfId) use ($cabinetsByOdf, $odfsById): array {
                $odf = $items->first()['odf'];
                $model = $odfsById->get($odfId);
                $splitters = (int) $cabinetsByOdf->get($odfId, collect())->sum('splitter_count');
                $fiberCapacity = (int) ($model?->fiber_capacity ?? 0);

                return [
                    'id' => $odf['id'],
                    'name' => $odf['name'],
                    'lat' => $odf['lat'],
                    'lng' => $odf['lng'],
                    'house_count' => $items->count(),
                    'splitter_count' => $splitters,
                    'fiber_capacity' => $fiberCapacity,
                    'utilization_percent' => $fiberCapacity > 0 ? (int) round($splitters / $fiberCapacity * 100) : 0,
                    'route_m' => (int) $items->sum('length_m'),
                ];
            })
            ->sortByDesc('house_count')
            ->values()
            ->all();
    }

    private function qualitySummary(array $routes, array $cabinets, array $odfs, int $unroutedCount, int $maxDrop = self::MAX_DROP_M): array
    {
        $dropLengths = collect($cabinets)->flatMap(fn (array $cabinet) => collect($cabinet['drop_preview'] ?? [])->pluck('length_m'))->values();
        $utilizations = collect($cabinets)->pluck('utilization_percent')->filter(fn ($value) => $value !== null)->values();
        $warnings = [];

        foreach ($cabinets as $cabinet) {
            if (($cabinet['max_drop_m'] ?? 0) > $maxDrop) {
                $warnings[] = "{$cabinet['name']} ima drop duzi od {$maxDrop} m ({$cabinet['max_drop_m']} m).";
            }
            if (($cabinet['utilization_percent'] ?? 0) < 50) {
                $warnings[] = "{$cabinet['name']} je slabo popunjen ({$cabinet['utilization_percent']}%).";
            }
        }
        $forcedCount = collect($routes)->where('capacity_forced', true)->count();
        if ($forcedCount > 0) {
            $warnings[] = "{$forcedCount} kuca je prebaceno na drugi ODF zbog kapaciteta.";
        }
        foreach ($odfs as $odf) {
            if (($odf['fiber_capacity'] ?? 0) > 0 && ($odf['splitter_count'] ?? 0) > ($odf['fiber_capacity'] ?? 0)) {
                $warnings[] = "{$odf['name']} prelazi kapacitet vlakana ({$odf['splitter_count']}/{$odf['fiber_capacity']}).";
            } elseif (($odf['utilization_percent'] ?? 0) >= 85) {
                $warnings[] = "{$odf['name']} je blizu kapaciteta ({$odf['utilization_percent']}%).";
            }
        }
        if ($unroutedCount > 0) {
            $warnings[] = "{$unroutedCount} kuca nije dobilo GIS rutu.";
        }

        $averageDrop = $dropLengths->count() ? (int) round($dropLengths->avg()) : 0;
        $maxDrop = $dropLengths->count() ? (int) $dropLengths->max() : 0;
        $avgUtilization = $utilizations->count() ? (int) round($utilizations->avg()) : 0;
        $score = 100;
        $score -= min(45, max(0, $averageDrop - 60) * 0.4);
        $score -= min(25, count($warnings) * 8);
        $score -= min(20, $unroutedCount * 5);
        $score += min(10, max(0, $avgUtilization - 70) * 0.2);

        return [
            'average_drop_m' => $averageDrop,
            'max_drop_m' => $maxDrop,
            'average_utilization_percent' => $avgUtilization,
            'score' => max(0, min(100, (int) round($score))),
            'warnings' => $warnings,
        ];
    }

    private function addRouteToNetwork(array &$network, array $path, House $house): void
    {
        for ($i = 1; $i < count($path); $i++) {
            $a = $this->normalizedPoint($path[$i - 1]);
            $b = $this->normalizedPoint($path[$i]);
            if ($a === $b) {
                continue;
            }

            $forward = $this->pointKey($a).'|'.$this->pointKey($b);
            $reverse = $this->pointKey($b).'|'.$this->pointKey($a);
            $key = isset($network[$reverse]) ? $reverse : $forward;

            if (! isset($network[$key])) {
                $network[$key] = [
                    'path' => [$a, $b],
                    'houses' => [],
                    'length_m' => $this->distance($a, $b),
                ];
            }

            $network[$key]['houses'][$house->id] = $house->label;
        }
    }

    private function networkSegments(array $network): array
    {
        return collect($network)
            ->map(function (array $segment, string $key): array {
                return [
                    'key' => $key,
                    'path' => $segment['path'],
                    'length_m' => (int) $segment['length_m'],
                    'house_count' => count($segment['houses']),
                    'houses' => array_values($segment['houses']),
                ];
            })
            ->sortByDesc('house_count')
            ->values()
            ->all();
    }

    private function housePayload(House $house): array
    {
        return [
            'id' => $house->id,
            'label' => $house->label,
            'address' => $house->address,
            'lat' => (float) $house->latitude,
            'lng' => (float) $house->longitude,
        ];
    }

    private function normalizedPoint(array $point): array
    {
        return [round((float) $point[0], 7), round((float) $point[1], 7)];
    }

    private function pointKey(array $point): string
    {
        return $point[0].','.$point[1];
    }

    private function distance(array $a, array $b): int
    {
        return (int) round($this->meters($a, $b));
    }

    private function meters(array $a, array $b): float
    {
        $lat1 = deg2rad((float) $a[0]);
        $lat2 = deg2rad((float) $b[0]);
        $dLat = deg2rad((float) $b[0] - (float) $a[0]);
        $dLng = deg2rad((float) $b[1] - (float) $a[1]);
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * (sin($dLng / 2) ** 2);

        return 6371000 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    private function fiberCountForLoad(int $houseCount): int
    {
        return match (true) {
            $houseCount <= 4 => 4,
            $houseCount <= 12 => 12,
            $houseCount <= 24 => 24,
            default => 48,
        };
    }

    private function splitterCount(int $houseCount): int
    {
        return max(1, (int) ceil($houseCount / 4));
    }

    private function uniqueCabinetName(Project $project, string $base): string
    {
        $name = $base;
        $suffix = 2;
        while (Cabinet::where('project_id', $project->id)->where('name', $name)->exists()) {
            $name = $base.'-'.$suffix++;
        }

        return $name;
    }

    private function uniqueRouteName(Project $project, string $base): string
    {
        $name = $base;
        $suffix = 2;
        while (NetworkRoute::where('project_id', $project->id)->where('name', $name)->exists()) {
            $name = $base.'-'.$suffix++;
        }

        return $name;
    }
}

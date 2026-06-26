<?php

namespace App\Services\PlannerLab;

use App\Models\Project;

/**
 * Orchestrator za Planner Lab preview.
 *
 * Algoritam (po specifikaciji):
 *  1. Grupiraj kuće u ODO grupe (medoid klastera)
 *  2. Učitaj OSM puteve za bounding box projekta
 *  3. Izgradi road graph
 *  4. Snapuj ODF na najbliži čvor u grafu
 *  5. Snapuj svaki ODO na najbliži čvor
 *  6. Dijkstra shortest path: ODF → svaki ODO (kroz road graph)
 *  7. Spoji sve pathove → branching stablo (bez duplikata)
 *  8. ODO lat/lng = snapped pozicija na cesti
 *  9. Dodijeli kuće odgovarajućim ODO-ima
 * 10. Vrati rezultat (nema write u DB)
 *
 * Fallback na ucrtane rute → fallback na ravne linije.
 */
class PlannerLabService
{
    public function __construct(
        protected PlannerCabinetEngine   $cabinetEngine,
        protected PlannerRoadGraphEngine $graphEngine,
        protected PlannerRoadEngine      $roadEngine,
        protected PlannerScoringEngine   $scoringEngine,
    ) {}

    public function preview(Project $project, array $options = []): array
    {
        $options = array_merge([
            'useOsm'             => true,
            'maxHousesPerCabinet'=> 12,
            'odoSpacing'         => 150,  // ne koristi se u graph modu, ali za fallback
            'maxDropDistance'    => 150,
            'installation'       => 'underground',
        ], $options);

        $this->cabinetEngine->reset();
        $this->roadEngine->reset();

        $project->load(['odfs', 'houses', 'routes']);

        $houses = $project->houses->map(fn ($h) => [
            'id'        => $h->id,
            'label'     => $h->label,
            'latitude'  => $h->latitude,
            'longitude' => $h->longitude,
        ])->toArray();

        $odfs = $project->odfs->map(fn ($o) => [
            'id'        => $o->id,
            'name'      => $o->name,
            'latitude'  => $o->latitude,
            'longitude' => $o->longitude,
        ])->values()->toArray();

        if (empty($odfs) || empty($houses)) {
            return $this->emptyPlan($options, count($odfs), count($houses));
        }

        $odf = $odfs[0];

        // ── 1. Grupiraj kuće u ODO grupe (medoid) ──────────────────────────
        $groups = $this->cabinetEngine->groupHouses($houses, $options);
        $odoCandidates = [];
        foreach ($groups as $group) {
            $cab = $this->cabinetEngine->placeCabinet($group, []);
            $odoCandidates[] = $cab; // lat, lng = medoid
        }

        // ── 2. OSM road graph (ako useOsm=true) ─────────────────────────────
        $graph = null;
        if ($options['useOsm']) {
            $bbox    = $this->computeBbox($odf, $houses, 0.008); // ~800m padding
            $osmWays = $this->graphEngine->loadOsmRoads(...$bbox);

            if (! empty($osmWays)) {
                $graph = $this->graphEngine->buildGraph($osmWays);
            }
        }

        // ── 3a. Ako imamo road graph → graph-based routing ─────────────────
        if ($graph !== null && ! empty($graph['nodes'])) {
            return $this->planFromGraph($odf, $houses, $odoCandidates, $graph, $options);
        }

        // ── 3b. Fallback: koristi ucrtane kabl rute ─────────────────────────
        $cableRoutes = $project->routes
            ->whereIn('route_type', ['backbone', 'feeder', 'distribution'])
            ->values()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'route_type' => $r->route_type, 'path' => $r->path])
            ->toArray();

        if (! empty($cableRoutes)) {
            return $this->planFromExistingRoutes($odf, $houses, $cableRoutes, $options);
        }

        // ── 3c. Fallback: OSRM / ravne linije ──────────────────────────────
        return $this->planFromOsrm($odf, $houses, $odoCandidates, $options);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Algoritam A: OSM Road Graph (shortest path + merge)
    // ════════════════════════════════════════════════════════════════════════

    private function planFromGraph(
        array $odf,
        array $houses,
        array $odoCandidates,
        array $graph,
        array $options
    ): array {
        $spacing    = max(80, (int) ($options['odoSpacing'] ?? 150));
        $maxDrop    = (float) ($options['maxDropDistance'] ?? 150);
        $maxH       = (int) ($options['maxHousesPerCabinet'] ?? 12);
        $maxBranch  = min(12, (int) ceil(count($houses) / max(1, $maxH * 2)));

        $nodes = $graph['nodes'];
        $adj   = $graph['adj'];

        // Snap ODF na najbliži čvor
        $odfSnap = $this->graphEngine->snapToNearestNode(
            (float) $odf['latitude'], (float) $odf['longitude'], $nodes
        );

        if ($odfSnap['node_id'] === null) {
            return $this->planFromOsrm($odf, $houses, $odoCandidates, $options);
        }

        $plannedCabinets = [];
        $plannedRoutes   = [];
        $plannedBranches = [];
        $remaining       = $houses;
        $odoCtr          = 1;
        $branchNum       = 1;
        $odfPos          = ['latitude' => $odfSnap['lat'], 'longitude' => $odfSnap['lng']];

        while (! empty($remaining) && $branchNum <= $maxBranch) {
            $prevCount = count($remaining);

            // Najudaljenija neraspoređena kuća → krak u tom smjeru
            $target     = $this->furthestHouse($odfPos, $remaining);
            $targetSnap = $this->graphEngine->snapToNearestNode(
                $target['lat'], $target['lng'], $nodes
            );

            if ($targetSnap['node_id'] === null) {
                break;
            }

            // Dijkstra: ODF → najudaljenija kuća
            $djResult = $this->graphEngine->dijkstra(
                $nodes, $adj, $odfSnap['node_id'], $targetSnap['node_id']
            );

            if ($djResult === null || count($djResult['path']) < 2) {
                break;
            }

            // Postavi ODO-e svakih $spacing metara duž te rute
            $allOdos = $this->placeAlongPath($djResult['path'], $spacing, $branchNum, $odoCtr);
            $nextCtr = $odoCtr + count($allOdos); // napreduj brojač za SVE postavljene, ne samo zadržane

            // Dodijeli kuće koje su blizu ove rute
            [$allOdos, $remaining] = $this->assignHouses($allOdos, $remaining, $maxDrop, $maxH);
            $odos = array_values(array_filter($allOdos, fn ($o) => $o['house_count'] > 0));

            if (empty($odos) || count($remaining) === $prevCount) {
                // Nema napretka — preskoci
                if (count($remaining) === $prevCount) {
                    break;
                }
                $odoCtr = $nextCtr;
                $branchNum++;
                continue;
            }

            $odoCtr = $nextCtr;
            $branchKey    = 'K-' . $branchNum;
            $branchTempId = 'branch-' . $branchKey;

            foreach ($odos as $odo) {
                $plannedCabinets[] = $odo;
            }

            $plannedRoutes[] = [
                'temp_id'        => 'route-' . $branchKey,
                'name'           => $branchKey,
                'branch_temp_id' => $branchTempId,
                'branch_name'    => $branchKey,
                'type'           => 'distribution',
                'installation'   => $options['installation'],
                'path'           => $djResult['path'],
                'length_m'       => round($djResult['length_m'], 1),
                'source'         => 'graph',
                'follows_road'   => true,
                'temporary'      => true,
            ];

            $plannedBranches[] = [
                'temp_id'        => $branchTempId,
                'name'           => $branchKey,
                'cabinets'       => $odos,
                'cabinet_count'  => count($odos),
                'length_m'       => round($djResult['length_m'], 1),
                'straight_count' => 0,
            ];

            $branchNum++;
        }

        // Preostale kuće forsirano dodjeli najbližem ODO-u
        if (! empty($remaining) && ! empty($plannedCabinets)) {
            $this->forcedAssign($remaining, $plannedCabinets);
        }

        // Konsoliduj slabo popunjene ODO-e (< 4 kuće) u komšije
        $plannedCabinets = $this->consolidateOdos($plannedCabinets, $maxH);

        // Ažuriraj cabinets listu u branchovima (reference su stale nakon konsolidacije)
        $cabByTempId = [];
        foreach ($plannedCabinets as $c) {
            $cabByTempId[$c['temp_id']] = $c;
        }
        foreach ($plannedBranches as &$branch) {
            $branch['cabinets'] = array_values(array_filter(
                array_map(fn ($c) => $cabByTempId[$c['temp_id']] ?? null, $branch['cabinets'])
            ));
            $branch['cabinet_count'] = count($branch['cabinets']);
        }
        unset($branch);

        // Ukloni prazne krakove i njihove rute
        $validBranchIds  = [];
        $plannedBranches = array_values(array_filter($plannedBranches, function ($b) use (&$validBranchIds) {
            if ($b['cabinet_count'] > 0) {
                $validBranchIds[$b['temp_id']] = true;
                return true;
            }
            return false;
        }));
        $plannedRoutes = array_values(array_filter(
            $plannedRoutes,
            fn ($r) => isset($validBranchIds[$r['branch_temp_id'] ?? ''])
        ));

        return $this->buildResult($plannedCabinets, $plannedRoutes, $plannedBranches, $houses, [], $options);
    }

    /**
     * Flatten niza segmenata u jedan polyline (bez duplikata na spojevima).
     */
    private function flattenSegments(array $segments): array
    {
        $path = [];
        foreach ($segments as $seg) {
            if (empty($path)) {
                $path = $seg;
            } else {
                foreach (array_slice($seg, 1) as $pt) {
                    $path[] = $pt;
                }
            }
        }
        return $path;
    }

    // ════════════════════════════════════════════════════════════════════════
    // Algoritam B: Ucrtane kabl rute
    // ════════════════════════════════════════════════════════════════════════

    private function planFromExistingRoutes(array $odf, array $houses, array $cableRoutes, array $options): array
    {
        $spacing  = max(80, (int) ($options['odoSpacing'] ?? 150));
        $maxDrop  = (float) ($options['maxDropDistance'] ?? 150);
        $maxH     = (int) ($options['maxHousesPerCabinet'] ?? 12);

        $plannedCabinets = [];
        $plannedRoutes   = [];
        $plannedBranches = [];
        $remaining       = $houses;
        $odoCtr          = 1;

        $typeOrder = ['backbone' => 0, 'feeder' => 1, 'distribution' => 2];
        usort($cableRoutes, fn ($a, $b) =>
            ($typeOrder[$a['route_type']] ?? 3) <=> ($typeOrder[$b['route_type']] ?? 3));

        foreach ($cableRoutes as $branchNum => $route) {
            $path = $route['path'];
            if (count($path) < 2) {
                continue;
            }

            $allOdos = $this->placeAlongPath($path, $spacing, $branchNum + 1, $odoCtr);
            $nextCtr = $odoCtr + count($allOdos);

            [$allOdos, $remaining] = $this->assignHouses($allOdos, $remaining, $maxDrop, $maxH);
            $odos = array_values(array_filter($allOdos, fn ($o) => $o['house_count'] > 0));

            if (empty($odos)) {
                $odoCtr = $nextCtr;
                continue;
            }

            $odoCtr = $nextCtr;
            $branchKey    = 'K-' . ($branchNum + 1);
            $branchTempId = 'branch-' . $branchKey;

            foreach ($odos as $odo) {
                $plannedCabinets[] = $odo;
            }

            $routeLen = PlannerGeometry::pathLength($path);

            $plannedRoutes[] = [
                'temp_id'        => 'route-' . $branchKey,
                'name'           => $route['name'] ?: $branchKey,
                'branch_temp_id' => $branchTempId,
                'branch_name'    => $branchKey,
                'type'           => $route['route_type'],
                'installation'   => $options['installation'],
                'path'           => $path,
                'length_m'       => $routeLen,
                'source'         => 'existing',
                'follows_road'   => true,
                'temporary'      => false,
            ];

            $plannedBranches[] = [
                'temp_id'        => $branchTempId,
                'name'           => $branchKey,
                'cabinets'       => $odos,
                'cabinet_count'  => count($odos),
                'length_m'       => $routeLen,
                'straight_count' => 0,
            ];
        }

        if (! empty($remaining) && ! empty($plannedCabinets)) {
            $this->forcedAssign($remaining, $plannedCabinets);
        }

        $plannedCabinets = $this->consolidateOdos($plannedCabinets, $maxH);

        return $this->buildResult($plannedCabinets, $plannedRoutes, $plannedBranches, $houses, [], $options);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Algoritam C: OSRM / ravne linije (last resort)
    // ════════════════════════════════════════════════════════════════════════

    private function planFromOsrm(array $odf, array $houses, array $odoCandidates, array $options): array
    {
        $spacing   = max(80, (int) ($options['odoSpacing'] ?? 150));
        $maxDrop   = (float) ($options['maxDropDistance'] ?? 150);
        $maxH      = (int) ($options['maxHousesPerCabinet'] ?? 12);
        $maxBranch = min(8, (int) ceil(count($houses) / max(1, $maxH)));

        $plannedCabinets = [];
        $plannedRoutes   = [];
        $plannedBranches = [];
        $routeWarnings   = [];
        $remaining       = $houses;
        $branchNum       = 1;
        $odoCtr          = 1;

        while (! empty($remaining) && $branchNum <= $maxBranch) {
            $prevCount = count($remaining);
            $target    = $this->furthestHouse($odf, $remaining);

            $routeResult = $this->roadEngine->routeToPoint($odf, $target, $options);
            $odos        = $this->placeAlongPath($routeResult['path'], $spacing, $branchNum, $odoCtr);

            [$odos, $remaining] = $this->assignHouses($odos, $remaining, $maxDrop, $maxH);
            $odos = array_values(array_filter($odos, fn ($o) => $o['house_count'] > 0));

            if (empty($odos) || count($remaining) === $prevCount) {
                if (! empty($remaining) && ! empty($plannedCabinets)) {
                    $this->forcedAssign($remaining, $plannedCabinets);
                }
                break;
            }

            $odoCtr += count($odos);
            $branchKey    = 'K-' . $branchNum;
            $branchTempId = 'branch-' . $branchKey;

            $finalRoute = $this->roadEngine->routeBranch($odf, $odos, $options);

            if (! $finalRoute['follows_road']) {
                $routeWarnings[] = ['level' => 'warning', 'message' => $branchKey . ': ne prati cestu.', 'lat' => null, 'lng' => null];
            }

            foreach ($odos as $j => &$odo) {
                $wp = $finalRoute['snapped_waypoints'][$j + 1] ?? null;
                if ($wp) {
                    $odo['lat'] = $wp['lat'];
                    $odo['lng'] = $wp['lng'];
                }
            }
            unset($odo);

            foreach ($odos as $odo) {
                $plannedCabinets[] = $odo;
            }

            $plannedRoutes[] = [
                'temp_id'        => 'route-' . $branchKey,
                'name'           => $branchKey,
                'branch_temp_id' => $branchTempId,
                'branch_name'    => $branchKey,
                'type'           => 'distribution',
                'installation'   => $options['installation'],
                'path'           => $finalRoute['path'],
                'length_m'       => $finalRoute['length_m'],
                'source'         => $finalRoute['source'],
                'follows_road'   => $finalRoute['follows_road'],
                'temporary'      => true,
            ];

            $plannedBranches[] = [
                'temp_id'        => $branchTempId,
                'name'           => $branchKey,
                'cabinets'       => $odos,
                'cabinet_count'  => count($odos),
                'length_m'       => $finalRoute['length_m'],
                'straight_count' => $finalRoute['follows_road'] ? 0 : 1,
            ];

            $branchNum++;
        }

        return $this->buildResult($plannedCabinets, $plannedRoutes, $plannedBranches, $houses, $routeWarnings, $options);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Shared helpers
    // ════════════════════════════════════════════════════════════════════════

    /** Računaj bounding box za sve tačke + padding */
    private function computeBbox(array $odf, array $houses, float $pad = 0.01): array
    {
        $lats = array_map(fn ($h) => (float) $h['latitude'], $houses);
        $lngs = array_map(fn ($h) => (float) $h['longitude'], $houses);

        $lats[] = (float) $odf['latitude'];
        $lngs[] = (float) $odf['longitude'];

        return [
            min($lats) - $pad,
            min($lngs) - $pad,
            max($lats) + $pad,
            max($lngs) + $pad,
        ];
    }

    /** Dodijeli SVE kuće ODO-ima (za graph mod gdje ODO kandidati su fiksni) */
    private function assignAllHouses(array $cabinets, array $houses, float $maxDrop, int $maxH): array
    {
        $warnings = [];

        foreach ($houses as $house) {
            $bestDist = $maxDrop * 3; // Veći radius za graph mod (snap na cesti garantuje bliže)
            $bestIdx  = null;

            foreach ($cabinets as $i => $cab) {
                if ($cab['house_count'] >= $maxH) {
                    continue;
                }
                $d = PlannerGeometry::haversine(
                    (float) $house['latitude'], (float) $house['longitude'],
                    $cab['lat'], $cab['lng']
                );
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx  = $i;
                }
            }

            if ($bestIdx !== null) {
                $cabinets[$bestIdx]['house_ids'][]  = $house['id'];
                $cabinets[$bestIdx]['house_count']++;
                $cabinets[$bestIdx]['splitters'] = $this->calcSplitters($cabinets[$bestIdx]['house_count']);
            } else {
                // Forsirano na najbliži (bez ograničenja distance)
                $bestDist = PHP_FLOAT_MAX;
                $bestIdx  = null;
                foreach ($cabinets as $i => $cab) {
                    $d = PlannerGeometry::haversine(
                        (float) $house['latitude'], (float) $house['longitude'],
                        $cab['lat'], $cab['lng']
                    );
                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $bestIdx  = $i;
                    }
                }
                if ($bestIdx !== null) {
                    $cabinets[$bestIdx]['house_ids'][]  = $house['id'];
                    $cabinets[$bestIdx]['house_count']++;
                    $cabinets[$bestIdx]['splitters'] = $this->calcSplitters($cabinets[$bestIdx]['house_count']);
                    if ($bestDist > $maxDrop) {
                        $warnings[] = [
                            'level'   => 'warning',
                            'message' => ($house['label'] ?? 'Kuća') . ' je ' . round($bestDist) . ' m od najbližeg ODO-a.',
                            'lat'     => $house['latitude'],
                            'lng'     => $house['longitude'],
                        ];
                    }
                }
            }
        }

        return [$cabinets, $warnings];
    }

    private function assignHouses(array $odos, array $houses, float $maxDist, int $maxH): array
    {
        $unassigned = [];
        foreach ($houses as $house) {
            $bestDist = $maxDist + 1;
            $bestIdx  = null;
            foreach ($odos as $i => $odo) {
                if ($odo['house_count'] >= $maxH) {
                    continue;
                }
                $d = PlannerGeometry::haversine(
                    (float) $house['latitude'], (float) $house['longitude'],
                    $odo['lat'], $odo['lng']
                );
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx  = $i;
                }
            }
            if ($bestIdx !== null) {
                $odos[$bestIdx]['house_ids'][]  = $house['id'];
                $odos[$bestIdx]['house_count']++;
                $odos[$bestIdx]['splitters'] = $this->calcSplitters($odos[$bestIdx]['house_count']);
            } else {
                $unassigned[] = $house;
            }
        }
        return [$odos, $unassigned];
    }

    /**
     * Spoji ODO-e s malo kuća u najbliže komšije koji imaju kapacitet.
     * Ponavlja prolaz dok ima šta za spojiti.
     */
    private function consolidateOdos(array $cabinets, int $maxH, int $minFill = 4): array
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            $cabinets = array_values($cabinets);

            foreach ($cabinets as $i => $cab) {
                if ($cab['house_count'] === 0) {
                    unset($cabinets[$i]);
                    $changed = true;
                    break;
                }

                if ($cab['house_count'] >= $minFill) {
                    continue;
                }

                // Traži najbliži ODO s dovoljno kapaciteta
                $bestJ    = null;
                $bestDist = PHP_FLOAT_MAX;

                foreach ($cabinets as $j => $other) {
                    if ($j === $i) {
                        continue;
                    }
                    if ($other['house_count'] + $cab['house_count'] > $maxH) {
                        continue;
                    }
                    $d = PlannerGeometry::haversine($cab['lat'], $cab['lng'], $other['lat'], $other['lng']);
                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $bestJ    = $j;
                    }
                }

                if ($bestJ !== null) {
                    foreach ($cab['house_ids'] as $hid) {
                        $cabinets[$bestJ]['house_ids'][] = $hid;
                    }
                    $cabinets[$bestJ]['house_count'] += $cab['house_count'];
                    $cabinets[$bestJ]['splitters']    = $this->calcSplitters($cabinets[$bestJ]['house_count']);
                    unset($cabinets[$i]);
                    $changed = true;
                    break;
                }
            }
        }

        return array_values($cabinets);
    }

    private function forcedAssign(array $houses, array &$cabinets): void
    {
        if (empty($cabinets)) return;
        foreach ($houses as $house) {
            $bestDist = PHP_FLOAT_MAX;
            $bestIdx  = null;
            foreach ($cabinets as $i => $cab) {
                $d = PlannerGeometry::haversine(
                    (float) $house['latitude'], (float) $house['longitude'],
                    $cab['lat'], $cab['lng']
                );
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx  = $i;
                }
            }
            if ($bestIdx !== null) {
                $cabinets[$bestIdx]['house_ids'][]  = $house['id'];
                $cabinets[$bestIdx]['house_count']++;
                $cabinets[$bestIdx]['splitters'] = $this->calcSplitters($cabinets[$bestIdx]['house_count']);
            }
        }
    }

    private function furthestHouse(array $odf, array $houses): array
    {
        $best = null;
        $bestDist = -1;
        foreach ($houses as $h) {
            $d = PlannerGeometry::haversine(
                (float) $odf['latitude'], (float) $odf['longitude'],
                (float) $h['latitude'], (float) $h['longitude']
            );
            if ($d > $bestDist) {
                $bestDist = $d;
                $best     = $h;
            }
        }
        return ['lat' => (float) $best['latitude'], 'lng' => (float) $best['longitude']];
    }

    private function placeAlongPath(array $path, float $spacing, int $branchNum, int $startCtr): array
    {
        if (count($path) < 2) {
            return [$this->makeOdo($path[0] ?? [0, 0], $branchNum, $startCtr)];
        }

        $path = $this->densifyPath($path, $spacing / 3.0);

        $odos = [];
        $accumulated = 0.0;
        $prev = $path[0];
        $ctr  = $startCtr;

        foreach (array_slice($path, 1) as $point) {
            $accumulated += PlannerGeometry::haversine($prev[0], $prev[1], $point[0], $point[1]);
            if ($accumulated >= $spacing) {
                $odos[]      = $this->makeOdo($point, $branchNum, $ctr++);
                $accumulated = 0.0;
            }
            $prev = $point;
        }

        $last = end($path);
        if (empty($odos)) {
            $odos[] = $this->makeOdo($last, $branchNum, $ctr++);
        } elseif (PlannerGeometry::haversine(end($odos)['lat'], end($odos)['lng'], $last[0], $last[1]) > $spacing * 0.35) {
            $odos[] = $this->makeOdo($last, $branchNum, $ctr++);
        }

        return $odos;
    }

    private function densifyPath(array $path, float $step): array
    {
        if ($step <= 0 || count($path) < 2) return $path;
        $result = [$path[0]];
        $prev   = $path[0];
        foreach (array_slice($path, 1) as $next) {
            $len = PlannerGeometry::haversine($prev[0], $prev[1], $next[0], $next[1]);
            if ($len > $step * 1.5) {
                $n = (int) ceil($len / $step);
                for ($i = 1; $i < $n; $i++) {
                    $t = $i / $n;
                    $result[] = [$prev[0] + $t * ($next[0] - $prev[0]), $prev[1] + $t * ($next[1] - $prev[1])];
                }
            }
            $result[] = $next;
            $prev     = $next;
        }
        return $result;
    }

    private function makeOdo(array $point, int $branchNum, int $ctr): array
    {
        return [
            'temp_id'     => 'odo-k' . $branchNum . '-' . $ctr,
            'name'        => sprintf('LAB-ODO-%03d', $ctr),
            'lat'         => (float) $point[0],
            'lng'         => (float) $point[1],
            'house_ids'   => [],
            'house_count' => 0,
            'splitters'   => 1,
            'road_snapped'=> true,
        ];
    }

    private function calcSplitters(int $count): int
    {
        if ($count <= 4) return 1;
        if ($count <= 8) return 2;
        return 3;
    }

    private function buildResult(
        array $plannedCabinets,
        array $plannedRoutes,
        array $plannedBranches,
        array $houses,
        array $routeWarnings,
        array $options
    ): array {
        $houseAssignments = $this->buildAssignments($plannedCabinets, $houses);

        $scoring = $this->scoringEngine->score([
            'planned_cabinets'  => $plannedCabinets,
            'planned_routes'    => $plannedRoutes,
            'planned_branches'  => $plannedBranches,
            'house_assignments' => $houseAssignments,
            'summary' => ['unassigned_houses' => count($houses) - count($houseAssignments)],
        ]);

        $fallbackCount = count(array_filter($plannedRoutes, fn ($r) => ($r['source'] ?? '') === 'straight'));

        return [
            'success'           => true,
            'plan_id'           => 'temp-' . uniqid(),
            'source'            => 'planner_lab',
            'temporary'         => true,
            'summary'           => [
                'total_houses'         => count($houses),
                'planned_cabinets'     => count($plannedCabinets),
                'planned_branches'     => count($plannedBranches),
                'planned_routes'       => count($plannedRoutes),
                'assigned_houses'      => count($houseAssignments),
                'unassigned_houses'    => count($houses) - count($houseAssignments),
                'total_route_length_m' => round(array_sum(array_column($plannedRoutes, 'length_m')), 1),
                'warnings_count'       => count($routeWarnings),
                'fallback_routes'      => $fallbackCount,
                'score'                => $scoring['score'],
                'grade'                => $scoring['grade'],
                'score_reasons'        => $scoring['reasons'],
                'branch_summary'       => $scoring['branch_summary'] ?? [],
            ],
            'planned_cabinets'  => $plannedCabinets,
            'planned_branches'  => $plannedBranches,
            'planned_routes'    => $plannedRoutes,
            'house_assignments' => $houseAssignments,
            'warnings'          => $routeWarnings,
            'debug'             => [
                'options'      => $options,
                'house_count'  => count($houses),
                'branch_count' => count($plannedBranches),
            ],
        ];
    }

    private function buildAssignments(array $cabinets, array $houses): array
    {
        $houseById = [];
        foreach ($houses as $h) {
            $houseById[$h['id']] = $h;
        }

        $result = [];
        foreach ($cabinets as $cab) {
            foreach ($cab['house_ids'] as $hid) {
                $h = $houseById[$hid] ?? null;
                if (! $h) continue;
                $result[] = [
                    'house_id'        => $hid,
                    'house_label'     => $h['label'] ?? '',
                    'house_lat'       => $h['latitude'],
                    'house_lng'       => $h['longitude'],
                    'cabinet_temp_id' => $cab['temp_id'],
                    'cabinet_name'    => $cab['name'],
                ];
            }
        }
        return $result;
    }

    private function emptyPlan(array $options, int $odfCount, int $houseCount): array
    {
        return [
            'success'           => false,
            'message'           => $odfCount === 0 ? 'Projekt nema ODF.' : 'Projekt nema kuća.',
            'planned_cabinets'  => [], 'planned_branches'  => [],
            'planned_routes'    => [], 'house_assignments' => [], 'warnings' => [],
            'summary' => [
                'total_houses' => $houseCount, 'planned_cabinets' => 0,
                'planned_branches' => 0, 'planned_routes' => 0,
                'assigned_houses' => 0, 'unassigned_houses' => $houseCount,
                'total_route_length_m' => 0, 'warnings_count' => 0,
                'fallback_routes' => 0, 'score' => 0, 'grade' => 'poor',
                'score_reasons' => [], 'branch_summary' => [],
            ],
            'debug' => ['options' => $options, 'house_count' => $houseCount, 'odf_count' => $odfCount],
        ];
    }
}

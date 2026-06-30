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
        protected PlannerDropEngine      $dropEngine,
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
        $this->dropEngine->reset();

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

        if (count($odfs) > 1) {
            return $this->previewMultiOdf($odfs, $houses, $project, $options);
        }

        return $this->planSingleOdf($odfs[0], $houses, $project, $options);
    }

    private function planSingleOdf(array $odf, array $houses, Project $project, array $options): array
    {
        // ── 1. Grupiraj kuće u ODO grupe (medoid) ──────────────────────────
        $groups = $this->cabinetEngine->groupHouses($houses, $options);
        $odoCandidates = [];
        foreach ($groups as $group) {
            $odoCandidates[] = $this->cabinetEngine->placeCabinet($group);
        }

        // ── 2. Road graph: lokalne ceste → OSM → fallback ───────────────────
        $graph = null;
        if (! empty($options['road_polylines'])) {
            // Korisnik je uploadovao/nacrtao vlastite ceste — koristi ih direktno
            $graph = $this->graphEngine->buildGraphFromPolylines(
                $options['road_polylines'],
                $options['excluded_polygons'] ?? []
            );
        } elseif ($options['useOsm']) {
            $bbox    = $this->computeBbox($odf, $houses, 0.008);
            $osmWays = $this->graphEngine->loadOsmRoads(...$bbox);
            if (! empty($osmWays)) {
                $graph = $this->graphEngine->buildGraph($osmWays);
            }
        }

        if ($graph !== null && ! empty($graph['nodes'])) {
            return $this->planFromGraph($odf, $houses, $odoCandidates, $graph, $options);
        }

        $cableRoutes = $project->routes
            ->whereIn('route_type', ['backbone', 'feeder', 'distribution'])
            ->values()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'route_type' => $r->route_type, 'path' => $r->path])
            ->toArray();

        if (! empty($cableRoutes)) {
            return $this->planFromExistingRoutes($odf, $houses, $cableRoutes, $options);
        }

        return $this->planFromOsrm($odf, $houses, $odoCandidates, $options);
    }

    private function previewMultiOdf(array $odfs, array $houses, Project $project, array $options): array
    {
        // Dodijeli svaku kuću najbližem ODF-u
        $odfGroups = array_fill_keys(array_column($odfs, 'id'), []);
        foreach ($houses as $house) {
            $bestId   = null;
            $bestDist = PHP_FLOAT_MAX;
            foreach ($odfs as $odf) {
                $d = PlannerGeometry::haversine(
                    (float) $house['latitude'], (float) $house['longitude'],
                    (float) $odf['latitude'],   (float) $odf['longitude']
                );
                if ($d < $bestDist) { $bestDist = $d; $bestId = $odf['id']; }
            }
            if ($bestId !== null) {
                $odfGroups[$bestId][] = $house;
            }
        }

        $mergedCabinets    = [];
        $mergedRoutes      = [];
        $mergedBranches    = [];
        $mergedDrops       = [];
        $mergedAssignments = [];
        $mergedWarnings    = [];

        foreach ($odfs as $odfIdx => $odf) {
            $odfHouses = $odfGroups[$odf['id']] ?? [];
            if (empty($odfHouses)) {
                $mergedWarnings[] = ['level' => 'info', 'message' => "{$odf['name']}: nema kuća u zoni ovog ODF-a.", 'lat' => $odf['latitude'], 'lng' => $odf['longitude']];
                continue;
            }

            $subPlan = $this->planSingleOdf($odf, $odfHouses, $project, $options);
            if (! ($subPlan['success'] ?? false)) {
                $mergedWarnings[] = ['level' => 'warning', 'message' => "{$odf['name']}: planiranje nije uspjelo.", 'lat' => $odf['latitude'], 'lng' => $odf['longitude']];
                continue;
            }

            // Dodaj prefiks svim temp_id-ovima da ne dođe do kolizije između ODF-a
            $p = "odf{$odfIdx}-";
            foreach ($subPlan['planned_cabinets'] as $cab) {
                $cab['temp_id'] = $p . $cab['temp_id'];
                $mergedCabinets[] = $cab;
            }
            foreach ($subPlan['planned_routes'] as $route) {
                $route['temp_id']        = $p . ($route['temp_id'] ?? '');
                $route['branch_temp_id'] = $p . ($route['branch_temp_id'] ?? '');
                $mergedRoutes[] = $route;
            }
            foreach ($subPlan['planned_branches'] as $branch) {
                $branch['temp_id'] = $p . ($branch['temp_id'] ?? '');
                $branch['cabinets'] = array_map(fn ($c) => array_merge($c, ['temp_id' => $p . ($c['temp_id'] ?? '')]), $branch['cabinets'] ?? []);
                $mergedBranches[] = $branch;
            }
            foreach ($subPlan['planned_drops'] as $drop) {
                $drop['temp_id']         = $p . ($drop['temp_id'] ?? '');
                $drop['cabinet_temp_id'] = $p . ($drop['cabinet_temp_id'] ?? '');
                $mergedDrops[] = $drop;
            }
            foreach ($subPlan['house_assignments'] as $ha) {
                $ha['cabinet_temp_id'] = $p . ($ha['cabinet_temp_id'] ?? '');
                $mergedAssignments[] = $ha;
            }
            foreach ($subPlan['warnings'] as $w) {
                $mergedWarnings[] = $w;
            }
        }

        $dropLengths     = array_column($mergedDrops, 'length_m');
        $totalDrop       = round(array_sum($dropLengths), 1);
        $avgDrop         = count($dropLengths) > 0 ? round(array_sum($dropLengths) / count($dropLengths)) : 0;
        $totalRoute      = round(array_sum(array_column($mergedRoutes, 'length_m')), 1);
        $longDrops       = count(array_filter($mergedDrops, fn ($d) => $d['length_m'] > ($options['maxDropDistance'] ?? 150)));
        $assignedCount   = count($mergedAssignments);
        $fallbackCount   = count(array_filter($mergedRoutes, fn ($r) => ($r['source'] ?? '') === 'straight'));

        $scoring = $this->scoringEngine->score([
            'planned_cabinets'  => $mergedCabinets,
            'planned_routes'    => $mergedRoutes,
            'planned_branches'  => $mergedBranches,
            'house_assignments' => $mergedAssignments,
            'planned_drops'     => $mergedDrops,
            'summary'           => ['unassigned_houses' => count($houses) - $assignedCount],
            'debug'             => ['options' => $options],
        ]);

        return [
            'success'           => true,
            'plan_id'           => 'temp-' . uniqid(),
            'source'            => 'planner_lab',
            'temporary'         => true,
            'summary'           => [
                'total_houses'         => count($houses),
                'planned_cabinets'     => count($mergedCabinets),
                'planned_branches'     => count($mergedBranches),
                'planned_routes'       => count($mergedRoutes),
                'assigned_houses'      => $assignedCount,
                'unassigned_houses'    => count($houses) - $assignedCount,
                'total_route_length_m' => $totalRoute,
                'total_drop_length_m'  => $totalDrop,
                'avg_drop_length_m'    => $avgDrop,
                'long_drops'           => $longDrops,
                'warnings_count'       => count($mergedWarnings),
                'fallback_routes'      => $fallbackCount,
                'score'                => $scoring['score'],
                'grade'                => $scoring['grade'],
                'score_reasons'        => $scoring['reasons'],
                'branch_summary'       => $scoring['branch_summary'] ?? [],
            ],
            'planned_cabinets'  => $mergedCabinets,
            'planned_branches'  => $mergedBranches,
            'planned_routes'    => $mergedRoutes,
            'planned_drops'     => $mergedDrops,
            'house_assignments' => $mergedAssignments,
            'warnings'          => $mergedWarnings,
            'debug'             => ['options' => $options, 'house_count' => count($houses), 'odf_count' => count($odfs)],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // Algoritam A: OSM Road Graph (Dijkstra → isti core kao FtthIntelligenceService)
    // ════════════════════════════════════════════════════════════════════════

    private function planFromGraph(
        array $odf,
        array $houses,
        array $odoCandidates,
        array $graph,
        array $options
    ): array {
        $nodes = $graph['nodes'];
        $adj   = $graph['adj'];

        $odfSnap = $this->graphEngine->snapToNearestNode(
            (float) $odf['latitude'], (float) $odf['longitude'], $nodes
        );
        if ($odfSnap['node_id'] === null) {
            return $this->planFromOsrm($odf, $houses, $odoCandidates, $options);
        }

        // Izgradi Dijkstra krakove od ODF-a do svakog ODO kandidata
        $branchDefs = [];
        foreach ($odoCandidates as $candidate) {
            $snap = $this->graphEngine->snapToNearestNode(
                (float) $candidate['lat'], (float) $candidate['lng'], $nodes
            );
            if ($snap['node_id'] === null || $snap['node_id'] === $odfSnap['node_id']) {
                continue;
            }
            $dj = $this->graphEngine->dijkstra($nodes, $adj, $odfSnap['node_id'], $snap['node_id']);
            if ($dj === null || count($dj['path']) < 2) {
                continue;
            }
            $branchDefs[] = ['path' => $dj['path'], 'length_m' => $dj['length_m'], 'source' => 'graph'];
        }

        if (empty($branchDefs)) {
            return $this->planFromOsrm($odf, $houses, $odoCandidates, $options);
        }

        // Filtriraj kratke krakove — kuće blizu ODF/glavnog voda ne trebaju vlastitu granu,
        // bit će dodijeljene najbližem dužem kraku u planFromBranchPaths.
        $minBranchLen = 100.0;
        $longBranches = array_values(array_filter($branchDefs, fn ($b) => $b['length_m'] >= $minBranchLen));
        // Ako su svi kratki (mali projekt), zadrži ih sve
        $branchDefs = ! empty($longBranches) ? $longBranches : $branchDefs;

        // Isti core algoritam kao FtthIntelligenceService
        $result = $this->planFromBranchPaths($branchDefs, $houses, $options);

        if (empty($result['planned_cabinets'])) {
            // Nema krakova blizu kuća → proba Dijkstra do siročića
            $maxDrop = (float) ($options['maxDropDistance'] ?? 150);
            $farHouses = $houses; // sve su dalje od maxDrop
            $extraBranches = $this->buildOrphanBranches($farHouses, $nodes, $adj, $odfSnap, $options);
            if (! empty($extraBranches)) {
                $result = $this->planFromBranchPaths(array_merge($branchDefs, $extraBranches), $houses, $options);
            }
        }

        if (empty($result['planned_cabinets'])) {
            return $this->planFromOsrm($odf, $houses, $odoCandidates, $options);
        }

        return $this->finalizeBranchesAndBuild(
            $result['planned_cabinets'],
            $result['planned_routes'],
            $result['planned_branches'],
            $houses, [], $options, skipReassign: true
        );
    }

    /**
     * Gradi Dijkstra krakove za "siročiće" (kuće daleko od svih grana).
     */
    private function buildOrphanBranches(
        array $farHouses, array $nodes, array $adj, array $odfSnap, array $options
    ): array {
        $branches = [];
        $orphanGroups = $this->cabinetEngine->groupHouses($farHouses, $options);
        foreach ($orphanGroups as $group) {
            if (empty($group)) {
                continue;
            }
            $cLat = array_sum(array_map(fn ($h) => (float) $h['latitude'], $group)) / count($group);
            $cLng = array_sum(array_map(fn ($h) => (float) $h['longitude'], $group)) / count($group);
            $snap = $this->graphEngine->snapToNearestNode($cLat, $cLng, $nodes);
            if ($snap['node_id'] === null) {
                continue;
            }
            $dj = $this->graphEngine->dijkstra($nodes, $adj, $odfSnap['node_id'], $snap['node_id']);
            if ($dj !== null && count($dj['path']) >= 2) {
                $branches[] = ['path' => $dj['path'], 'length_m' => $dj['length_m'], 'source' => 'graph'];
            }
        }
        return $branches;
    }

    private function makeBranchRoute(
        string $branchKey, string $branchTempId,
        array $path, float $lengthM, string $source, array $options
    ): array {
        return [
            'temp_id'        => 'route-' . $branchKey,
            'name'           => $branchKey,
            'branch_temp_id' => $branchTempId,
            'branch_name'    => $branchKey,
            'type'           => 'distribution',
            'installation'   => $options['installation'],
            'path'           => $path,
            'length_m'       => round($lengthM, 1),
            'source'         => $source,
            'follows_road'   => $source === 'graph',
            'temporary'      => true,
        ];
    }

    private function makeBranch(string $branchKey, string $branchTempId, array $odos, float $lengthM): array
    {
        return [
            'temp_id'        => $branchTempId,
            'name'           => $branchKey,
            'cabinets'       => $odos,
            'cabinet_count'  => count($odos),
            'length_m'       => round($lengthM, 1),
            'straight_count' => 0,
        ];
    }

    private function finalizeBranchesAndBuild(
        array $plannedCabinets,
        array $plannedRoutes,
        array $plannedBranches,
        array $houses,
        array $routeWarnings,
        array $options,
        bool $skipReassign = false
    ): array {
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

        return $this->buildResult($plannedCabinets, $plannedRoutes, $plannedBranches, $houses, $routeWarnings, $options, $skipReassign);
    }

    // ════════════════════════════════════════════════════════════════════════
    // SHARED CORE — direktni port FtthIntelligenceService algoritma
    // Radi s nizom path-ova (ne NetworkRoute), ali isti princip:
    //   svaka kuća → najbliži krak → sort po chainageu → grupe →
    //   ODO = medoid grupe projiciran na krak
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Isti algoritam kao FtthIntelligenceService::previewOdoPlan() ali s
     * generisanim kracima (array paths) umjesto NetworkRoute objekata.
     *
     * @param  array $branchDefs  [ ['path'=>[[lat,lng],...], 'length_m'=>float, 'source'=>str, ...], ... ]
     * @param  array $houses      [ ['id'=>int, 'latitude'=>float, 'longitude'=>float, ...], ... ]
     * @return array              ['planned_cabinets', 'planned_routes', 'planned_branches']
     */
    private function planFromBranchPaths(array $branchDefs, array $houses, array $options): array
    {
        $maxDrop  = (float) ($options['maxDropDistance'] ?? 150);
        $maxH = (int) ($options['maxHousesPerCabinet'] ?? 12);

        // 1. Svaka kuća → NAJBLIŽI krak (FtthIntelligenceService::assignHousesToBranches)
        $branchHouseMap = array_fill(0, count($branchDefs), []);
        $farHouses      = [];

        foreach ($houses as $house) {
            $hLat    = (float) $house['latitude'];
            $hLng    = (float) $house['longitude'];
            $bestIdx = null;
            $bestDist= PHP_FLOAT_MAX;
            $bestCh  = 0.0;

            foreach ($branchDefs as $bIdx => $branch) {
                $proj = PlannerGeometry::projectPointToPath($hLat, $hLng, $branch['path']);
                if ($proj['distance_m'] < $bestDist) {
                    $bestDist = $proj['distance_m'];
                    $bestIdx  = $bIdx;
                    $bestCh   = $proj['chainage_m'];
                }
            }

            if ($bestIdx !== null && $bestDist <= $maxDrop) {
                $house['_ch'] = $bestCh;
                $branchHouseMap[$bestIdx][] = $house;
            } else {
                $farHouses[] = $house;
            }
        }

        // 2. Za svaki krak: grupiraj po chainageu → ODO-i (FtthIntelligenceService::groupsForBranch)
        $plannedCabinets = [];
        $plannedRoutes   = [];
        $plannedBranches = [];
        $odoCtr          = 1;
        $branchNum       = 1;

        foreach ($branchDefs as $bIdx => $branch) {
            $branchHouses = $branchHouseMap[$bIdx] ?? [];
            if (empty($branchHouses)) {
                continue;
            }

            $path    = $branch['path'];
            $source  = $branch['source'] ?? 'graph';

            // Sortiraj po chainageu, podijeli ravnomjerno na grupe od max $maxH kuća.
            // Cilj: što bliže 12 po ODO-u — bez ikakvih dodatnih uvjeta.
            usort($branchHouses, fn ($a, $b) => $a['_ch'] <=> $b['_ch']);

            $n        = count($branchHouses);
            $nGroups  = max(1, (int) ceil($n / $maxH));
            $baseSize = (int) floor($n / $nGroups);
            $rem      = $n % $nGroups;
            $groups   = [];
            $offset   = 0;
            for ($g = 0; $g < $nGroups; $g++) {
                $size     = $baseSize + ($g < $rem ? 1 : 0);
                $groups[] = array_slice($branchHouses, $offset, $size);
                $offset  += $size;
            }

            // Kreiraj ODO za svaku grupu
            $branchKey    = 'K-' . $branchNum;
            $branchTempId = 'branch-' . $branchKey;
            $branchOdos   = [];

            foreach ($groups as $group) {
                // FtthIntelligenceService::odoPointForGroup — medoid grupe projiciran na krak
                $odoPt = $this->medoidOdoOnPath($group, $path);
                $odo   = $this->makeOdo($odoPt, $branchNum, $odoCtr++);
                $odo['branch_temp_id'] = $branchTempId;
                $odo['house_ids']      = array_map(fn ($h) => $h['id'], $group);
                $odo['house_count']    = count($group);
                $odo['splitters']      = $this->calcSplitters($odo['house_count']);

                $branchOdos[]      = $odo;
                $plannedCabinets[] = $odo;
            }

            // Odsijeci krak na poziciji zadnjeg ODO-a — sprječava prikaz
            // "petlje" kad Dijkstra putanja prati vijugavu cestu dalje od ODO-a.
            $maxOdoCh = 0.0;
            foreach ($branchOdos as $odo) {
                $proj = PlannerGeometry::projectPointToPath((float) $odo['lat'], (float) $odo['lng'], $path);
                if ($proj['chainage_m'] > $maxOdoCh) {
                    $maxOdoCh = $proj['chainage_m'];
                }
            }
            $routePath = $this->trimPathAtChainage($path, $maxOdoCh + 20.0);
            $routeLen  = PlannerGeometry::pathLength($routePath);
            $plannedRoutes[]   = $this->makeBranchRoute($branchKey, $branchTempId, $routePath, $routeLen, $source, $options);
            $plannedBranches[] = $this->makeBranch($branchKey, $branchTempId, $branchOdos, $routeLen);
            $branchNum++;
        }

        // 3. Kuće koje su predaleko od svih krakova → forsirano na najbliži ODO
        if (! empty($farHouses) && ! empty($plannedCabinets)) {
            $this->forcedAssign($farHouses, $plannedCabinets, $maxH);
        }

        // 4. Prebaci kuće iz malih ODO-a (< 4 kuće) na najbliži ODO koji ima mjesta.
        //    Mali ODO i cijeli njegov krak nestaju — nema smisla graditi granu za 1-3 kuće
        //    kad je susjed odmah pored i ima slobodnih portova.
        $this->redistributeSmallOdos($plannedCabinets, $maxH, $maxDrop * 2.5);

        return [
            'planned_cabinets' => $plannedCabinets,
            'planned_routes'   => $plannedRoutes,
            'planned_branches' => $plannedBranches,
        ];
    }

    /**
     * Premjesti kuće iz malih ODO-a (< 4 kuće) na najbliži ODO koji ima kapaciteta.
     * Mali ODO se briše — finalizeBranchesAndBuild automatski ukloni granu bez ODO-a.
     */
    private function redistributeSmallOdos(array &$cabinets, int $maxH, float $maxOdoDist, int $minFill = 4): void
    {
        $cabinets = array_values($cabinets);
        $changed  = true;

        while ($changed) {
            $changed = false;

            foreach ($cabinets as $i => $cab) {
                if ($cab['house_count'] >= $minFill) {
                    continue;
                }

                // Pronađi najbliži ODO s kapacitetom
                $bestJ    = null;
                $bestDist = $maxOdoDist;

                foreach ($cabinets as $j => $other) {
                    if ($j === $i) {
                        continue;
                    }
                    if ($other['house_count'] + $cab['house_count'] > $maxH) {
                        continue;
                    }
                    $d = PlannerGeometry::haversine(
                        (float) $cab['lat'], (float) $cab['lng'],
                        (float) $other['lat'], (float) $other['lng']
                    );
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
                    $cabinets = array_values($cabinets);
                    $changed  = true;
                    break;
                }

                // Nema susjeda u dosegu → ostavi kako je (geografski izolovan)
            }
        }
    }

    /**
     * ODO pozicija = medoid grupe projiciran na krak.
     * Ekvivalentno FtthIntelligenceService::odoPointForGroup().
     */
    private function medoidOdoOnPath(array $houses, array $path): array
    {
        // Medoid: kuća s minimalnom ukupnom udaljenošću do svih ostalih
        $best      = null;
        $bestTotal = PHP_FLOAT_MAX;
        foreach ($houses as $candidate) {
            $cLat  = (float) $candidate['latitude'];
            $cLng  = (float) $candidate['longitude'];
            $total = 0.0;
            foreach ($houses as $other) {
                $total += PlannerGeometry::haversine($cLat, $cLng, (float) $other['latitude'], (float) $other['longitude']);
            }
            if ($total < $bestTotal) {
                $bestTotal = $total;
                $best      = $candidate;
            }
        }

        // Projiciraj medoid na krak → ODO je NA cesti
        $proj = PlannerGeometry::projectPointToPath(
            (float) $best['latitude'], (float) $best['longitude'], $path
        );

        return [$proj['lat'], $proj['lng']];
    }

    /**
     * Odsijeci path na zadanoj chainageu — vraća prefiks putanje do te dužine.
     * Ako je maxCh veći od ukupne dužine patha, vraća cijeli path.
     */
    private function trimPathAtChainage(array $path, float $maxCh): array
    {
        if (count($path) < 2 || $maxCh <= 0) {
            return $path;
        }
        $result = [$path[0]];
        $ch     = 0.0;
        for ($i = 1, $n = count($path); $i < $n; $i++) {
            $segLen = PlannerGeometry::haversine(
                (float) $path[$i - 1][0], (float) $path[$i - 1][1],
                (float) $path[$i][0],     (float) $path[$i][1]
            );
            if ($ch + $segLen >= $maxCh) {
                $t        = $segLen > 0 ? ($maxCh - $ch) / $segLen : 0.0;
                $result[] = [
                    (float) $path[$i - 1][0] + $t * ((float) $path[$i][0] - (float) $path[$i - 1][0]),
                    (float) $path[$i - 1][1] + $t * ((float) $path[$i][1] - (float) $path[$i - 1][1]),
                ];
                return $result;
            }
            $result[] = $path[$i];
            $ch      += $segLen;
        }
        return $result;
    }

    // ════════════════════════════════════════════════════════════════════════
    // Algoritam B: Ucrtane kabl rute (isti core kao FtthIntelligenceService)
    // ════════════════════════════════════════════════════════════════════════

    private function planFromExistingRoutes(array $odf, array $houses, array $cableRoutes, array $options): array
    {
        $typeOrder = ['backbone' => 0, 'feeder' => 1, 'distribution' => 2];
        usort($cableRoutes, fn ($a, $b) =>
            ($typeOrder[$a['route_type']] ?? 3) <=> ($typeOrder[$b['route_type']] ?? 3));

        $branchDefs = [];
        foreach ($cableRoutes as $route) {
            $path = $route['path'] ?? [];
            if (count($path) < 2) {
                continue;
            }
            $branchDefs[] = [
                'path'      => $path,
                'length_m'  => PlannerGeometry::pathLength($path),
                'source'    => 'existing',
                'name'      => $route['name'] ?? '',
                'route_type'=> $route['route_type'] ?? 'distribution',
            ];
        }

        if (empty($branchDefs)) {
            return $this->planFromOsrm($odf, $houses, [], $options);
        }

        $result = $this->planFromBranchPaths($branchDefs, $houses, $options);

        return $this->finalizeBranchesAndBuild(
            $result['planned_cabinets'],
            $result['planned_routes'],
            $result['planned_branches'],
            $houses, [], $options, skipReassign: true
        );
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
            $branchKey    = 'K-' . $branchNum;
            $branchTempId = 'branch-' . $branchKey;

            $odos = $this->placeAlongPath($routeResult['path'], $spacing, $branchNum, $odoCtr);
            foreach ($odos as &$o) { $o['branch_temp_id'] = $branchTempId; }
            unset($o);

            [$odos, $remaining] = $this->assignHouses($odos, $remaining, $maxDrop, $maxH);
            $odos = array_values(array_filter($odos, fn ($o) => $o['house_count'] > 0));

            if (empty($odos) || count($remaining) === $prevCount) {
                if (! empty($remaining) && ! empty($plannedCabinets)) {
                    $this->forcedAssign($remaining, $plannedCabinets, $maxH);
                }
                break;
            }

            $odoCtr += count($odos);

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

    private function forcedAssign(array $houses, array &$cabinets, int $maxH = 12): void
    {
        if (empty($cabinets)) return;
        foreach ($houses as $house) {
            // First pass: nearest cabinet that still has capacity
            $bestDist = PHP_FLOAT_MAX;
            $bestIdx  = null;
            foreach ($cabinets as $i => $cab) {
                if ($cab['house_count'] >= $maxH) continue;
                $d = PlannerGeometry::haversine(
                    (float) $house['latitude'], (float) $house['longitude'],
                    $cab['lat'], $cab['lng']
                );
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx  = $i;
                }
            }
            // Fallback: assign to nearest even if over capacity
            if ($bestIdx === null) {
                $bestDist = PHP_FLOAT_MAX;
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
        array $options,
        bool $skipReassign = false
    ): array {
        // Chainage-based planovi (planFromGraph) rade dodjelu sami —
        // reassignToNearest bi pokvario pažljivo izračunate grupe.
        if (! $skipReassign) {
            $maxH       = (int) ($options['maxHousesPerCabinet'] ?? 12);
            $routePaths = [];
            foreach ($plannedRoutes as $r) {
                if (! empty($r['branch_temp_id']) && ! empty($r['path'])) {
                    $routePaths[$r['branch_temp_id']] = $r['path'];
                }
            }
            $maxDrop = (float) ($options['maxDropDistance'] ?? 150);
            $this->reassignToNearest($plannedCabinets, $houses, $maxH, $routePaths, $maxDrop);
        }

        $houseAssignments = $this->buildAssignments($plannedCabinets, $houses);

        $plannedDrops = $this->dropEngine->createDrops($plannedCabinets, $houses, $plannedRoutes, $options);

        // Collect drop warnings into the main warnings list
        foreach ($plannedDrops as $drop) {
            if ($drop['warning'] !== null) {
                $routeWarnings[] = $drop['warning'];
            }
        }

        $scoring = $this->scoringEngine->score([
            'planned_cabinets'  => $plannedCabinets,
            'planned_routes'    => $plannedRoutes,
            'planned_branches'  => $plannedBranches,
            'house_assignments' => $houseAssignments,
            'planned_drops'     => $plannedDrops,
            'summary' => ['unassigned_houses' => count($houses) - count($houseAssignments)],
            'debug'   => ['options' => $options],
        ]);

        $fallbackCount    = count(array_filter($plannedRoutes, fn ($r) => ($r['source'] ?? '') === 'straight'));
        $dropLengths      = array_column($plannedDrops, 'length_m');
        $totalDropLength  = round(array_sum($dropLengths), 1);
        $avgDropLength    = count($dropLengths) > 0 ? round(array_sum($dropLengths) / count($dropLengths)) : 0;
        $longDrops        = count(array_filter($plannedDrops, fn ($d) => $d['length_m'] > ($options['maxDropDistance'] ?? 150)));

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
                'total_drop_length_m'  => $totalDropLength,
                'avg_drop_length_m'    => $avgDropLength,
                'long_drops'           => $longDrops,
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
            'planned_drops'     => $plannedDrops,
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

    /**
     * Pametna dodjela kuća ODO-ima — greedy sorted-pairs algoritam.
     *
     * Koraci:
     *  1. Predizračunaj score za svaki par (kuća, ODO):
     *       score = distDoKraka + distDoOdo × 0.3
     *     Perpendikularna udaljenost do kraka (rute) je primarna metrika jer
     *     drop kabel prati T-putanju: ODO → ruta → kuća. ODO-ova udaljenost
     *     je sekundarni tie-breaker (0.3× težina).
     *
     *  2. Sortiraj sve parove po score-u (rastući).
     *
     *  3. Greedy prolaz: uzimaj parove redom — ako kuća još nije dodijeljena
     *     i ODO ima kapacitet, dodijeli. Svaka kuća se pojavljuje u listi
     *     za sve ODO-e pa će uvijek naći prvog slobodnog.
     *
     *  4. Fallback za kuće kojima su svi ODO-i puni: dodijeli na najmanje
     *     opterećen ODO (bez kapacitetnog ograničenja) s penalom za prekoračenje,
     *     čime se overflow ravnomjerno raspoređuje po susjednim ODO-ima.
     */
    private function reassignToNearest(
        array &$cabinets,
        array $houses,
        int $maxH,
        array $routePaths = [],
        float $maxDrop = 150.0
    ): void {
        foreach ($cabinets as &$cab) {
            $cab['house_ids']   = [];
            $cab['house_count'] = 0;
            $cab['splitters']   = 1;
        }
        unset($cab);

        if (empty($cabinets) || empty($houses)) {
            return;
        }

        // ── 1. Predizračunaj sve (kuća, ODO) scoreove ────────────────────────
        $pairs = []; // [hIdx, cabIdx, score]

        foreach ($houses as $hIdx => $house) {
            $hLat = (float) $house['latitude'];
            $hLng = (float) $house['longitude'];

            foreach ($cabinets as $cabIdx => $cab) {
                $dOdo     = PlannerGeometry::haversine($hLat, $hLng, (float) $cab['lat'], (float) $cab['lng']);
                $branchId = $cab['branch_temp_id'] ?? null;

                if ($branchId && isset($routePaths[$branchId])) {
                    $proj  = PlannerGeometry::projectPointToPath($hLat, $hLng, $routePaths[$branchId]);
                    $score = $proj['distance_m'] + $dOdo * 0.3;
                } else {
                    $score = $dOdo;
                }

                $pairs[] = [$hIdx, $cabIdx, $score];
            }
        }

        // ── 2. Sortiraj parove po score-u (manji = bolji) ────────────────────
        usort($pairs, fn ($a, $b) => $a[2] <=> $b[2]);

        // ── 3. Greedy dodjela uz poštivanje kapaciteta ───────────────────────
        $assignedHouses = []; // hIdx → true

        foreach ($pairs as [$hIdx, $cabIdx, $score]) {
            if (isset($assignedHouses[$hIdx])) {
                continue; // kuća već dodijeljena
            }
            if ($cabinets[$cabIdx]['house_count'] >= $maxH) {
                continue; // ODO pun → sljedeći par u listi
            }

            $cabinets[$cabIdx]['house_ids'][]  = $houses[$hIdx]['id'];
            $cabinets[$cabIdx]['house_count']++;
            $cabinets[$cabIdx]['splitters']    = $this->calcSplitters($cabinets[$cabIdx]['house_count']);
            $assignedHouses[$hIdx]             = true;
        }

        // ── 4. Fallback: kuće kojima su svi ODO-i bili puni ─────────────────
        // Svrstaj na najmanje opterećeni ODO s penalom za svaku kuću iznad maxH.
        // Pen 50m/kuća drži overflow na najblizem ODO, a ne na slučajnom.
        foreach ($houses as $hIdx => $house) {
            if (isset($assignedHouses[$hIdx])) {
                continue;
            }

            $hLat      = (float) $house['latitude'];
            $hLng      = (float) $house['longitude'];
            $bestIdx   = null;
            $bestScore = PHP_FLOAT_MAX;

            foreach ($cabinets as $cabIdx => $cab) {
                $dOdo     = PlannerGeometry::haversine($hLat, $hLng, (float) $cab['lat'], (float) $cab['lng']);
                $branchId = $cab['branch_temp_id'] ?? null;
                $overload = max(0, $cab['house_count'] - $maxH);

                if ($branchId && isset($routePaths[$branchId])) {
                    $proj  = PlannerGeometry::projectPointToPath($hLat, $hLng, $routePaths[$branchId]);
                    $score = $proj['distance_m'] + $dOdo * 0.3 + $overload * 50;
                } else {
                    $score = $dOdo + $overload * 50;
                }

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestIdx   = $cabIdx;
                }
            }

            if ($bestIdx !== null) {
                $cabinets[$bestIdx]['house_ids'][]  = $house['id'];
                $cabinets[$bestIdx]['house_count']++;
                $cabinets[$bestIdx]['splitters']    = $this->calcSplitters($cabinets[$bestIdx]['house_count']);
            }
        }
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

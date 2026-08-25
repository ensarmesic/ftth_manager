<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Models\SurveyPoint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports geodetic survey points from the surveyor's TXT export
 * (`broj  X  Y  Z  opis`, Gauss-Krüger) and reconstructs the network from
 * them as a GRAPH, not as a sequence:
 *
 *  1. Every trench point is a graph node; points that physically coincide
 *     (≤ NODE_MERGE_M) are merged — the surveyor returning to a fork, or
 *     logging a section in reverse, lands on the same node.
 *  2. Consecutive trench points in the walk (gap ≤ TRENCH_GAP_M) become
 *     edges. Each edge carries the DUCT identities read from the field
 *     description: "14/10 mc Zelena + Plava" = green and blue ducts,
 *     "10/8 mc x 2 - ZO 3" = two 10/8s belonging to cabinet ZO 3.
 *  3. Physical trenches = chains of the full graph cut at junctions.
 *     Each duct = chains of its identity's subgraph (additionally cut where
 *     the duct count changes), so branches keep their real ENDS at houses
 *     and backbones stay continuous through forks regardless of survey
 *     order, direction or description typos.
 */
class SurveyPointImportService
{
    /** Points in a trench are logged every 2-10 m; a bigger jump within the
     *  walk means the surveyor moved to another branch. */
    private const TRENCH_GAP_M = 20.0;

    /** A labelled customer duct may have a wider surveyed span between two vertices. */
    private const TAGGED_DUCT_GAP_M = self::TRENCH_GAP_M;

    /** Two survey points closer than this are the same physical spot. */
    private const NODE_MERGE_M = 1.5;

    private const ODF_MERGE_M = 15.0;

    private const EXISTING_ELEMENT_TOLERANCE_M = 5.0;

    private const DUCT_ENDPOINT_BIND_M = 30.0;

    /** A customer edge must obey the same surveyed-point gap as every trench edge. */
    private const CUSTOMER_SPUR_TO_TRENCH_M = self::TRENCH_GAP_M;

    public function __construct(
        private readonly GeoTransformService $transform,
        private readonly GeometryService $geometry,
        private readonly SurveyPointParser $parser,
        private readonly SurveyPointClassifier $classifier,
        private readonly SurveyImportMaintenanceService $maintenance,
        private readonly SurveyPathGeometryService $pathGeometry,
        private readonly SurveyImportIdentityService $identity,
        private readonly SurveyDuctBindingService $ductBinding,
        private readonly SurveyRouteReconciliationService $routeReconciliation,
        private readonly SurveyChainWalker $chainWalker,
        private readonly SurveyDuctIdentityService $ductIdentity,
        private readonly SurveyGraphBuilder $graphBuilder,
        private readonly SurveyColoredFlowService $coloredFlow,
        private readonly SurveyDropRoutingService $dropRouting,
        private readonly SurveyPreviewQualityService $previewQuality,
        private readonly SurveyBaseElementImportService $baseElementImporter,
        private readonly SurveyNetworkPersistenceService $networkPersistence,
        private readonly SurveyMainRouteGraphService $mainRouteGraph,
        private readonly SurveyRouteEntryPointService $routeEntryPoint,
        private readonly SurveyCustomerRouteReconstructor $customerRouteReconstructor,
    ) {}

    // -------------------------------------------------------------------------
    // Parsing & classification
    // -------------------------------------------------------------------------

    /**
     * Parse the raw TXT content into classified points (no DB writes).
     * Handles "glued" lines where the instrument merged two records into one.
     */
    public function parse(string $contents): array
    {
        return $this->parser->parse($contents, fn (string $code): array => $this->classify($code));
    }

    /**
     * Classify a free-hand surveyor description.
     *
     * @return array{kind:string,microduct_type:?string,microduct_count:int,colors:array,zo_tag:?string}
     */
    public function classify(string $code): array
    {
        return $this->classifier->classify($code);
    }

    public function clearImportedData(Project $project): array
    {
        return $this->maintenance->clearImportedData($project);
    }

    public function importedBatches(Project $project): array
    {
        return $this->maintenance->importedBatches($project);
    }

    public function clearImportedBatch(Project $project, string $batch): array
    {
        return $this->maintenance->clearImportedBatch($project, $batch);
    }

    // -------------------------------------------------------------------------
    // Graph reconstruction
    // -------------------------------------------------------------------------

    /**
     * Build the trench/duct network from classified points.
     *
     * @return array{trenches: array, ducts: array}
     */
    public function buildNetwork(
        array $points,
        array $existingCabinetPoints = [],
        array $existingTrenchPaths = []
    ): array {
        // Repair an omitted ZO number only when the immediately surrounding surveyed
        // 10/8 points agree (e.g. Z1, "-Z-Slinga", Z1).
        for ($i = 1; $i < count($points) - 1; $i++) {
            if (($points[$i]['kind'] ?? null) !== 'sling'
                || ($points[$i]['microduct_type'] ?? null) !== '10/8'
                || ($points[$i]['zo_tag'] ?? null) !== null) {
                continue;
            }
            $beforeTag = null;
            $afterTag = null;
            for ($j = $i - 1; $j >= max(0, $i - 3); $j--) {
                if (($points[$j]['zo_tag'] ?? null) !== null) {
                    $beforeTag = $points[$j]['zo_tag'];
                    break;
                }
            }
            for ($j = $i + 1; $j <= min(count($points) - 1, $i + 3); $j++) {
                if (($points[$j]['zo_tag'] ?? null) !== null) {
                    $afterTag = $points[$j]['zo_tag'];
                    break;
                }
            }
            if ($beforeTag !== null && $beforeTag === $afterTag) {
                $points[$i]['zo_tag'] = $beforeTag;
            }
        }

        // A terminal omitted from the rendered trench list must still break the field
        // walk when the following run records a side branch and returns near an older
        // node. Otherwise filtering out the terminal invents a shortcut from the trench
        // before the house directly to the trench after it (1443 -> 1445).
        $breakTrenchBefore = [];
        for ($index = 1; $index + 4 < count($points); $index++) {
            $terminal = $points[$index];
            $first = $points[$index + 1];
            $second = $points[$index + 2];
            $third = $points[$index + 3];
            $afterGap = $points[$index + 4];
            if (! in_array($terminal['kind'] ?? null, ['sling', 'loop'], true)
                || ($terminal['microduct_type'] ?? null) !== '10/8'
                || ($terminal['zo_tag'] ?? null) === null
                || collect([$first, $second, $third, $afterGap])->contains(
                    fn (array $point) => ($point['kind'] ?? null) !== 'trench'
                        || ($point['zo_tag'] ?? null) !== $terminal['zo_tag']
                )
                || (int) $first['point_no'] !== (int) $terminal['point_no'] + 1
                || (int) $second['point_no'] !== (int) $first['point_no'] + 1
                || (int) $third['point_no'] !== (int) $second['point_no'] + 1
                || (int) $afterGap['point_no'] <= (int) $third['point_no'] + 1
                || $this->geometry->distanceBetweenPoints(
                    [(float) $second['lat'], (float) $second['lng']],
                    [(float) $third['lat'], (float) $third['lng']]
                ) > 0.5) {
                continue;
            }
            $breakTrenchBefore[$index + 1] = true;
        }
        $trenchPoints = [];
        $localTrenchSegment = 0;
        $pendingSegmentBreak = false;
        foreach ($points as $index => $point) {
            $isTrenchPoint = $point['kind'] === 'trench'
                || (in_array($point['kind'], ['sling', 'loop'], true)
                    && preg_match('/\brov\b|rov\+/i', $point['code'] ?? ''));
            if (! $isTrenchPoint) {
                if ($trenchPoints !== [] && preg_match('/\bnapomena\b/i', (string) ($point['code'] ?? ''))) {
                    $pendingSegmentBreak = true;
                }

                continue;
            }
            if (isset($breakTrenchBefore[$index]) || $pendingSegmentBreak) {
                $localTrenchSegment++;
                $pendingSegmentBreak = false;
            }
            $point['_segment_no'] = ((int) ($point['_segment_no'] ?? 0) * 1000) + $localTrenchSegment;
            $trenchPoints[] = $point;
        }
        // 'loop' (a reserve coil, no house) still carries the duct through it, same as a
        // plain unmarked trench point — only 'sling' (an explicit house) ends a duct.
        $ductPoints = array_values(array_filter($points, fn ($p) => in_array($p['kind'], ['trench', 'sling', 'loop'], true)));
        $terminalCoordinates = collect($points)
            ->whereIn('kind', ['sling', 'loop'])
            ->map(fn (array $point) => [$point['lat'], $point['lng']])
            ->values()
            ->all();
        $terminalPointNumbers = collect($points)
            ->whereIn('kind', ['sling', 'loop'])
            ->pluck('point_no')
            ->map(fn ($pointNumber) => (int) $pointNumber)
            ->values()
            ->all();

        [$trenchNodes, $trenchEdges] = $this->graphBuilder->build(
            $trenchPoints,
            self::NODE_MERGE_M,
            self::CUSTOMER_SPUR_TO_TRENCH_M,
            self::TAGGED_DUCT_GAP_M,
            self::TRENCH_GAP_M,
            $terminalCoordinates,
            $terminalPointNumbers,
        );
        $trenches = [];
        if (count($trenchEdges) > 0) {
            $trenchPaths = array_map(
                fn (array $chain) => array_map(fn (int $node) => $trenchNodes[$node], $chain['nodes']),
                $this->chainWalker->walk($trenchEdges, null)
            );
            $trenchPaths = $this->pathGeometry->mergeCollinearChains($trenchPaths);

            foreach ($trenchPaths as $path) {
                $trenches[] = [
                    'path' => $path,
                    'points' => count($path),
                    'length_m' => $this->geometry->polylineLength($path),
                    'code' => 'rov',
                ];
            }

            $pathSegments = collect($trenchPoints)->mapWithKeys(fn (array $point) => [
                round((float) $point['lat'], 7).','.round((float) $point['lng'], 7) => (int) $point['_segment_no'],
            ]);
            $connectors = [];
            foreach ($trenches as $leftIndex => $left) {
                foreach (array_slice($trenches, $leftIndex + 1, null, true) as $rightIndex => $right) {
                    $leftEndpoints = [$left['path'][0], end($left['path'])];
                    $rightEndpoints = [$right['path'][0], end($right['path'])];
                    $best = null;
                    foreach ($leftEndpoints as $leftPoint) {
                        foreach ($rightEndpoints as $rightPoint) {
                            $leftSegment = $pathSegments->get(round($leftPoint[0], 7).','.round($leftPoint[1], 7));
                            $rightSegment = $pathSegments->get(round($rightPoint[0], 7).','.round($rightPoint[1], 7));
                            if ($leftSegment === null || $rightSegment === null || $leftSegment === $rightSegment) {
                                continue;
                            }
                            $distance = $this->geometry->distanceBetweenPoints($leftPoint, $rightPoint);
                            if ($distance > self::TRENCH_GAP_M || $distance <= self::NODE_MERGE_M) {
                                continue;
                            }
                            if ($best === null || $distance < $best['distance']) {
                                $best = ['distance' => $distance, 'path' => [$leftPoint, $rightPoint]];
                            }
                        }
                    }
                    if ($best !== null) {
                        $connectors[] = [
                            'path' => $best['path'],
                            'points' => 2,
                            'length_m' => $best['distance'],
                            'code' => 'rov-spoj',
                            '_routing_only' => true,
                        ];
                    }
                }
            }
            $trenches = array_merge($trenches, $connectors);
        }

        $usedTrenchCoordinates = collect($trenches)->flatMap(fn (array $trench) => $trench['path'])
            ->map(fn (array $point) => round($point[0], 7).','.round($point[1], 7))->flip();
        foreach ($trenchPoints as $point) {
            $key = round((float) $point['lat'], 7).','.round((float) $point['lng'], 7);
            if ($usedTrenchCoordinates->has($key)) {
                continue;
            }
            $nearTerminal = collect($terminalCoordinates)->contains(fn (array $terminal) => $this->geometry->distanceMeters($point['lat'], $point['lng'], $terminal[0], $terminal[1]) <= self::CUSTOMER_SPUR_TO_TRENCH_M
            );
            if ($nearTerminal) {
                $trenches[] = [
                    'path' => [[$point['lat'], $point['lng']]],
                    'points' => 1,
                    'length_m' => 0.0,
                    'code' => 'rov-kraj',
                ];
            }
        }

        [$ductNodes, $ductEdges, $dropCheckpointNodes] = $this->graphBuilder->build(
            $ductPoints,
            self::NODE_MERGE_M,
            self::CUSTOMER_SPUR_TO_TRENCH_M,
            self::TAGGED_DUCT_GAP_M,
            self::TRENCH_GAP_M,
        );

        // --- ducts: per-identity subgraph, additionally cut where count changes ---
        $identAttrs = [];
        $identEdges = [];
        foreach ($ductEdges as $edgeIndex => $edge) {
            foreach ($edge['idents'] as $ik => $attrs) {
                $identAttrs[$ik] = $attrs;
                $identEdges[$ik][] = $edgeIndex;
            }
        }

        $ducts = [];
        foreach ($identEdges as $ik => $edgeIndexes) {
            $sub = array_map(fn (int $ei) => $ductEdges[$ei] + ['group' => $ductEdges[$ei]['idents'][$ik]['count']], $edgeIndexes);
            $attrs = $identAttrs[$ik];
            $componentOf = $this->pathGeometry->connectedComponents($sub);

            $byCount = [];
            // Customer drops are independent end-to-end routes; distribution ducts stay
            // split into their physical graph segments.
            if ($attrs['type'] === '10/8') {
                // Customer drops: a survey walk often passes house/loop A on its way to B.
                // Each one still needs its OWN full path back to the shared trunk/cabinet
                // (not a continuation through A's drop), so emit one path per checkpoint
                // reached, reusing the shared prefix — see walkHouseDropChains().
                foreach ($this->chainWalker->walkHouseDrops($sub, $dropCheckpointNodes, fn (array $e) => $e['group']) as $chain) {
                    if (count($chain['nodes']) < 2) {
                        continue;
                    }
                    $byCount[$chain['group']][] = [
                        'path' => array_map(fn (int $node) => $ductNodes[$node], $chain['nodes']),
                        'component' => $componentOf[$chain['nodes'][0]] ?? $chain['nodes'][0],
                    ];
                }
            } else {
                foreach ($this->chainWalker->walk($sub, fn (array $e) => $e['group']) as $chain) {
                    if (count($chain['nodes']) < 2) {
                        continue;
                    }
                    $chainCount = $sub[$chain['edges'][0]]['group'];
                    $byCount[$chainCount][] = [
                        'path' => array_map(fn (int $node) => $ductNodes[$node], $chain['nodes']),
                        'component' => $componentOf[$chain['nodes'][0]] ?? $chain['nodes'][0],
                    ];
                }
            }

            foreach ($byCount as $chainCount => $paths) {
                // A colour or ZO tag identifies ONE physical duct network-wide, so its chain
                // ends weld across the small unsurveyed skips where a walk jumped back near
                // an earlier junction to record another branch (see weldChainEnds() for why
                // this is safe against the multi-house case above).
                if ($attrs['type'] !== '10/8' && ($attrs['color'] !== null || $attrs['tag'] !== null)) {
                    $paths = $this->pathGeometry->weldChainEnds($paths, 10.0);
                }
                foreach ($paths as $entry) {
                    $ducts[] = [
                        'key' => $ik,
                        'label' => $this->ductIdentity->label($attrs, (int) $chainCount),
                        'microduct_type' => $attrs['type'],
                        'microduct_count' => (int) $chainCount,
                        'color' => $attrs['color'],
                        'zo_tag' => $attrs['tag'],
                        'transit' => (bool) ($attrs['transit'] ?? false),
                        'path' => $entry['path'],
                        'length_m' => $this->geometry->polylineLength($entry['path']),
                    ];
                }
            }
        }

        $ducts = $this->coloredFlow->process(
            $ducts,
            $trenches,
            $points,
            self::NODE_MERGE_M,
            self::EXISTING_ELEMENT_TOLERANCE_M,
            self::DUCT_ENDPOINT_BIND_M,
            self::TRENCH_GAP_M,
        );
        $routingTrenches = array_merge(
            array_map(fn (array $trench) => $trench + ['_routing_source' => 'survey'], $trenches),
            array_map(fn (array $path) => ['path' => $path, '_routing_source' => 'existing'], $existingTrenchPaths)
        );
        $cabinetCoordinates = collect();
        for ($pointIndex = 1; $pointIndex < count($points); $pointIndex++) {
            $left = $points[$pointIndex - 1];
            $right = $points[$pointIndex];
            $cabinet = ($left['kind'] ?? null) === 'cabinet' ? $left : ((($right['kind'] ?? null) === 'cabinet') ? $right : null);
            $trench = ($left['kind'] ?? null) === 'trench' ? $left : ((($right['kind'] ?? null) === 'trench') ? $right : null);
            if ($cabinet !== null && $trench !== null
                && $this->geometry->distanceMeters($cabinet['lat'], $cabinet['lng'], $trench['lat'], $trench['lng']) <= self::NODE_MERGE_M) {
                $cabinetCoordinates->push([(float) $cabinet['lat'], (float) $cabinet['lng']]);
            }
        }
        foreach ($routingTrenches as &$routingTrench) {
            if (count($routingTrench['path'] ?? []) < 2) {
                continue;
            }
            foreach ($cabinetCoordinates as $cabinetCoordinate) {
                $firstDistance = $this->geometry->distanceBetweenPoints($routingTrench['path'][0], $cabinetCoordinate);
                $lastDistance = $this->geometry->distanceBetweenPoints(end($routingTrench['path']), $cabinetCoordinate);
                if ($firstDistance <= self::NODE_MERGE_M && $firstDistance > 0.05) {
                    array_unshift($routingTrench['path'], $cabinetCoordinate);
                } elseif ($lastDistance <= self::NODE_MERGE_M && $lastDistance > 0.05) {
                    $routingTrench['path'][] = $cabinetCoordinate;
                }
            }
            $routingTrench['path'] = $this->geometry->compactPath($routingTrench['path']);
        }
        unset($routingTrench);
        // A customer first follows its own surveyed spur, then newly surveyed shared
        // trenches from this TXT, then the already saved main network. All three are
        // physical geometry and must participate in one graph.
        $strictRoutingTrenches = $routingTrenches;
        $needsStrictRouting = collect($ducts)->contains(
            fn (array $duct) => ($duct['microduct_type'] ?? null) === '10/8'
        ) || collect($points)->contains(
            fn (array $point) => ($point['kind'] ?? null) === 'sling'
                && ($point['target_zo_explicit'] ?? false)
        );
        $strictMainGraph = $needsStrictRouting ? $this->mainRouteGraph->build(array_map(
            fn (array $trench, int $index) => [
                'id' => ($trench['_routing_source'] ?? 'survey').':'.$index,
                'path' => $trench['path'],
            ],
            $strictRoutingTrenches,
            array_keys($strictRoutingTrenches),
        )) : ['nodes' => [], 'edges' => []];
        $entryRoutes = array_map(
            fn (array $trench, int $index) => [
                'id' => ($trench['_routing_source'] ?? 'survey').':'.$index,
                'path' => $trench['path'],
            ],
            $strictRoutingTrenches,
            array_keys($strictRoutingTrenches),
        );
        foreach ($ducts as &$duct) {
            if (($duct['microduct_type'] ?? null) !== '10/8' || count($duct['path'] ?? []) < 2) {
                continue;
            }
            $duct['entry'] = $this->routeEntryPoint->find($duct['path'], $entryRoutes);
        }
        unset($duct);
        $cabinetRoutingTrenches = $routingTrenches;
        $preRoutingPaths = collect($ducts)
            ->filter(fn (array $duct) => isset($duct['_terminal_point']))
            ->mapWithKeys(fn (array $duct) => [(int) $duct['_terminal_point'] => $duct['path'] ?? []]);
        $ducts = $this->dropRouting->process(
            $ducts,
            $routingTrenches,
            $cabinetRoutingTrenches,
            $points,
            array_merge($points, $existingCabinetPoints),
        );
        $strictUserPaths = collect($ducts)
            ->filter(fn (array $duct) => isset($duct['_terminal_point']))
            ->mapWithKeys(function (array $duct) use ($preRoutingPaths): array {
                $path = $preRoutingPaths->get((int) $duct['_terminal_point'], $duct['path'] ?? []);
                $entryIndex = isset($duct['entry']['user_segment_end_index'])
                    ? max(1, (int) $duct['entry']['user_segment_end_index'])
                    : count($path) - 1;

                return [(int) $duct['_terminal_point'] => array_slice($path, 0, $entryIndex + 1)];
            });

        $terminalByNumber = collect($points)->whereIn('kind', ['sling', 'loop'])->keyBy('point_no');
        $strictCabinets = collect(array_merge($points, $existingCabinetPoints))
            ->where('kind', 'cabinet')
            ->map(fn (array $cabinet, int $index) => [
                'id' => $cabinet['id'] ?? 'survey:'.$index,
                'name' => (string) ($cabinet['code'] ?? $cabinet['name'] ?? ''),
                'coordinate' => [(float) $cabinet['lat'], (float) $cabinet['lng']],
            ])
            ->values()
            ->all();
        foreach ($ducts as &$duct) {
            if (($duct['microduct_type'] ?? null) !== '10/8' || ! isset($duct['_terminal_point'])) {
                continue;
            }
            $terminal = $terminalByNumber->get((int) $duct['_terminal_point']);
            if ($terminal === null) {
                continue;
            }
            $duct['strict_reconstruction'] = $this->customerRouteReconstructor->reconstruct(
                (string) ($terminal['code'] ?? $duct['label'] ?? ''),
                $strictUserPaths->get((int) $duct['_terminal_point'], $duct['path'] ?? []),
                $entryRoutes,
                $strictCabinets,
                $strictMainGraph,
            );
            $duct['cabinet_reached'] = $duct['strict_reconstruction']['status'] === 'complete';
            if (! $duct['cabinet_reached']) {
                $duct['routing_warnings'] = array_values(array_unique(array_merge(
                    $duct['routing_warnings'] ?? [],
                    $duct['strict_reconstruction']['warnings'] ?? [],
                )));
            }
        }
        unset($duct);

        return ['trenches' => $trenches, 'ducts' => $ducts, 'main_route_graph' => $strictMainGraph];
    }

    // -------------------------------------------------------------------------
    // Preview & confirm
    // -------------------------------------------------------------------------

    public function preview(Project $project, string $contents, string $filename = ''): array
    {
        $points = $this->parse($contents);
        if (count($points) < 1) {
            throw new InvalidArgumentException('Fajl ne sadrzi nijednu prepoznatljivu tacku (broj X Y Z opis).');
        }

        $batch = sha1($contents);
        $alreadyImported = SurveyPoint::where('project_id', $project->id)->where('import_batch', $batch)->exists();

        // Same reasoning as houses below: a cabinet point from THIS batch isn't a real
        // Cabinet row yet either, so it's added as a stand-in (id=null) alongside real DB
        // cabinets — otherwise the most common case (one file with both the ZO points and
        // its ducts) would preview every duct as unmatched, then bind correctly on confirm().
        $cabinets = Cabinet::where('project_id', $project->id)->whereNotNull('latitude')->get()
            ->concat(
                collect($points)->where('kind', 'cabinet')->map(fn ($p) => (object) [
                    'id' => null,
                    'name' => $this->identity->cabinetLabel($p['code']),
                    'latitude' => $p['lat'],
                    'longitude' => $p['lng'],
                ])
            );
        $network = $this->buildNetwork(
            $points,
            $cabinets->map(fn ($cabinet) => [
                'kind' => 'cabinet',
                'code' => $cabinet->name,
                'lat' => (float) $cabinet->latitude,
                'lng' => (float) $cabinet->longitude,
            ])->values()->all(),
            $this->routeReconciliation->projectRoutingTrenchPaths($project->id, $batch, self::TRENCH_GAP_M)
        );
        $odfs = Odf::where('project_id', $project->id)->whereNotNull('latitude')->get();
        // Sling points from THIS batch aren't persisted as House rows yet (preview never
        // writes), so they're added as house-shaped stand-ins alongside real DB houses —
        // otherwise a duct ending at a brand new house would preview as non-drop and then
        // flip to 'drop' on confirm(), where houses are created before ducts.
        $houseCandidates = House::where('project_id', $project->id)->whereNotNull('latitude')->get()
            ->concat(
                collect($points)
                    ->where('kind', 'sling')
                    ->map(fn ($p) => (object) ['id' => null, 'latitude' => $p['lat'], 'longitude' => $p['lng']])
            );

        $quality = $this->previewQuality->analyze($points, $network['ducts']);

        return [
            'batch' => $batch,
            'filename' => $filename,
            'already_imported' => $alreadyImported,
            'total_points' => count($points),
            'survey_points' => collect($points)->map(fn (array $point) => [
                'point_no' => $point['point_no'],
                'x' => $point['x'],
                'y' => $point['y'],
                'z' => $point['z'],
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'code' => $point['code'],
            ])->values()->all(),
            'by_kind' => collect($points)->groupBy('kind')->map->count()->all(),
            'trench_runs' => collect($network['trenches'])->reject(fn (array $chain) => $chain['_routing_only'] ?? false)->map(fn (array $chain) => [
                'trench_group' => 'Geodetski rov',
                'code' => $chain['code'],
                'points' => $chain['points'],
                'length_m' => $chain['length_m'],
                'microduct_type' => null,
                'microduct_count' => 0,
                'path' => $chain['path'],
            ])->values()->all(),
            'trench_network_count' => collect($network['trenches'])->contains(fn (array $chain) => ! ($chain['_routing_only'] ?? false)) ? 1 : 0,
            'trench_total_m' => collect($network['trenches'])->reject(fn (array $chain) => $chain['_routing_only'] ?? false)->sum('length_m'),
            'ducts' => collect($network['ducts'])->map(function (array $duct) use ($cabinets, $odfs, $houseCandidates) {
                $binding = $this->ductBinding->resolve(
                    $duct,
                    $cabinets,
                    $odfs,
                    $houseCandidates,
                    [],
                    self::EXISTING_ELEMENT_TOLERANCE_M,
                    self::DUCT_ENDPOINT_BIND_M,
                );
                $legacyCabinetReached = (bool) ($duct['cabinet_reached'] ?? false)
                    || ($binding['cabinet'] !== null && min(...array_map(
                        fn (array $endpoint) => $this->geometry->distanceMeters(
                            (float) $binding['cabinet']->latitude, (float) $binding['cabinet']->longitude,
                            $endpoint[0], $endpoint[1]
                        ),
                        $this->pathEndpoints($duct['path'])
                    )) <= self::DUCT_ENDPOINT_BIND_M);
                // An explicitly targeted user route is complete only when strict graph
                // reconstruction really reached that ZO. Proximity-based legacy binding
                // must not turn an unresolved red spur into a false green result.
                $cabinetReached = isset($duct['strict_reconstruction'])
                    ? ($duct['strict_reconstruction']['status'] ?? null) === 'complete'
                    : $legacyCabinetReached;

                return [
                    'key' => $duct['key'],
                    'terminal_point' => $duct['_terminal_point'] ?? null,
                    'label' => $duct['label'],
                    'length_m' => $duct['length_m'],
                    'color' => $duct['color'],
                    'zo_tag' => $duct['zo_tag'],
                    'route_type' => $binding['route_type'],
                    'matched_cabinet_id' => $binding['cabinet']?->id,
                    'matched_cabinet_name' => $binding['cabinet']?->name,
                    'match_confidence' => $binding['match_confidence'],
                    'candidates' => $binding['candidates'],
                    'routing_status' => (($duct['prepared_sling'] ?? false) || isset($duct['_terminal_point']))
                        ? ($cabinetReached ? 'complete' : 'unreachable')
                        : 'not_applicable',
                    'entry_point' => $duct['entry']['entry_point'] ?? null,
                    'entry_match_type' => $duct['entry']['match_type'] ?? null,
                    'entry_main_route_id' => $duct['entry']['main_route_id'] ?? null,
                    'entry_main_segment_index' => $duct['entry']['main_segment_index'] ?? null,
                    'target_zo' => $duct['target_zo'] ?? ($duct['zo_tag'] !== null ? 'ZO-'.$duct['zo_tag'] : null),
                    'target_zo_found' => $duct['strict_reconstruction']['target_zo_found'] ?? ($binding['cabinet'] !== null),
                    'target_zo_coordinate' => $duct['strict_reconstruction']['target_zo_coordinate'] ?? ($binding['cabinet'] !== null ? [
                        (float) $binding['cabinet']->latitude,
                        (float) $binding['cabinet']->longitude,
                    ] : null),
                    'own_geometry' => $duct['own_geometry'] ?? [],
                    'shared_main_geometry' => $duct['shared_main_geometry'] ?? [],
                    'shared_route_edges' => $duct['shared_route_edges'] ?? [],
                    'full_geometry' => $duct['path'],
                    'warnings' => $duct['routing_warnings'] ?? [],
                    'path' => $duct['path'],
                ];
            })->values()->all(),
            'cabinets' => collect($points)->where('kind', 'cabinet')->map(fn ($p) => ['code' => $p['code'], 'lat' => $p['lat'], 'lng' => $p['lng']])->values()->all(),
            'odfs' => $this->identity->mergeOdfPoints($points, self::ODF_MERGE_M),
            'manholes' => collect($points)->where('kind', 'manhole')->count(),
            'houses' => collect($points)->where('kind', 'sling')->count(),
            'prepared_slings' => collect($points)->where('kind', 'sling')->where('prepared_sling', true)->count(),
            'unrecognized_codes' => $quality['unrecognized_codes'],
            'quality' => collect($quality)->except('unrecognized_codes')->all(),
            'bounds' => [
                'lat' => [collect($points)->min('lat'), collect($points)->max('lat')],
                'lng' => [collect($points)->min('lng'), collect($points)->max('lng')],
            ],
        ];
    }

    /**
     * @param  array<string,int>  $cabinetOverrides  duct key => cabinet id, from a user-reviewed preview
     */
    public function confirm(Project $project, string $contents, string $filename = '', array $cabinetOverrides = [], array $routeCorrections = []): array
    {
        $points = $this->parse($contents);
        if (count($points) < 1) {
            throw new InvalidArgumentException('Fajl ne sadrzi nijednu prepoznatljivu tacku.');
        }

        $batch = sha1($contents);
        if (SurveyPoint::where('project_id', $project->id)->where('import_batch', $batch)->exists()) {
            throw new InvalidArgumentException('Ovaj fajl je vec uvezen u ovaj projekat.');
        }

        $created = ['points' => 0, 'trenches' => 0, 'ducts' => 0, 'cabinets' => 0, 'odfs' => 0, 'manholes' => 0, 'borings' => 0, 'splices' => 0, 'loops' => 0, 'houses' => 0];

        DB::transaction(function () use ($project, $points, $batch, $filename, $cabinetOverrides, $routeCorrections, &$created): void {
            $baseElements = $this->baseElementImporter->import(
                $project,
                $points,
                $batch,
                $filename,
                self::EXISTING_ELEMENT_TOLERANCE_M,
                self::ODF_MERGE_M,
            );
            foreach ($baseElements['counts'] as $type => $count) {
                $created[$type] += $count;
            }

            // 1. Cabinets and ODFs first, so ducts can bind to them.
            $allCabinets = $baseElements['cabinets'];

            $allOdfs = $baseElements['odfs'];

            // 2. Slings (points that explicitly name a house) mark a prepared house
            //    connection — create houses BEFORE ducts, so a duct ending at one can be
            //    bound and typed as a 'drop'. Its ODO is assigned after topology resolves.
            //    A bare reserve loop ('loop' kind,
            //    no house named) does NOT create a house — it's just a coiled cable reserve.
            $allHouses = $baseElements['houses'];

            $network = $this->buildNetwork(
                $points,
                $allCabinets->map(fn (Cabinet $cabinet) => [
                    'kind' => 'cabinet',
                    'code' => $cabinet->name,
                    'lat' => (float) $cabinet->latitude,
                    'lng' => (float) $cabinet->longitude,
                ])->values()->all(),
                $this->routeReconciliation->projectRoutingTrenchPaths($project->id, $batch, self::TRENCH_GAP_M)
            );

            foreach ($routeCorrections as $correction) {
                $path = $correction['path'] ?? null;
                if (! is_array($path) || count($path) < 2 || count($path) > 5000) {
                    throw new InvalidArgumentException('Ručna korekcija mora imati između 2 i 5000 tačaka.');
                }
                $normalizedPath = [];
                foreach ($path as $coordinate) {
                    if (! is_array($coordinate) || count($coordinate) !== 2
                        || ! is_numeric($coordinate[0]) || ! is_numeric($coordinate[1])
                        || (float) $coordinate[0] < -90 || (float) $coordinate[0] > 90
                        || (float) $coordinate[1] < -180 || (float) $coordinate[1] > 180) {
                        throw new InvalidArgumentException('Ručna korekcija sadrži neispravnu koordinatu.');
                    }
                    $normalizedPath[] = [round((float) $coordinate[0], 8), round((float) $coordinate[1], 8)];
                }
                $matched = false;
                foreach ($network['ducts'] as &$duct) {
                    if (($duct['key'] ?? null) !== ($correction['key'] ?? null)
                        || (int) ($duct['_terminal_point'] ?? 0) !== (int) ($correction['terminal_point'] ?? 0)) {
                        continue;
                    }
                    $duct['path'] = $this->geometry->compactPath($normalizedPath);
                    $duct['length_m'] = $this->geometry->polylineLength($duct['path']);
                    $matched = true;
                    break;
                }
                unset($duct);
                if (! $matched) {
                    throw new InvalidArgumentException('Izmijenjena mikrocijev više ne odgovara odabranoj TXT trasi. Ponovo otvori preview.');
                }
            }

            $networkCounts = $this->networkPersistence->persist(
                $project,
                $points,
                $network,
                $allCabinets,
                $allOdfs,
                $allHouses,
                $cabinetOverrides,
                $batch,
                self::EXISTING_ELEMENT_TOLERANCE_M,
                self::DUCT_ENDPOINT_BIND_M,
            );
            foreach ($networkCounts as $type => $count) {
                $created[$type] += $count;
            }

            // buildNetwork() already resolved this file's own topology (e.g. which branches
            // are genuinely separate at a junction) — findExisting*() must never second-guess
            // that by merging two routes THIS SAME call just created into each other just
            // because they happen to touch at a shared point. It only exists to continue a
            // route from an EARLIER, separate import, so freshly-created ids are excluded.
        });

        return $created;
    }

    private function pathEndpoints(array $path): array
    {
        return [$path[0], $path[count($path) - 1]];
    }

    /** Kept as a narrow compatibility seam for the focused topology regression test. */
    private function connectColoredCountTransitions(array $ducts, array $points, float $toleranceM): array
    {
        return $this->coloredFlow->connectColoredCountTransitions($ducts, $points, $toleranceM);
    }
}

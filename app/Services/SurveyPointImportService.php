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
    private const TAGGED_DUCT_GAP_M = 30.0;

    /** Two survey points closer than this are the same physical spot. */
    private const NODE_MERGE_M = 1.5;

    private const ODF_MERGE_M = 15.0;

    private const EXISTING_ELEMENT_TOLERANCE_M = 5.0;

    private const DUCT_ENDPOINT_BIND_M = 30.0;

    /** Maximum surveyed house spur distance to the established main trench corridor. */
    private const CUSTOMER_SPUR_TO_TRENCH_M = 60.0;

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

        $trenchPoints = array_values(array_filter($points, fn ($p) => $p['kind'] === 'trench'
            || (in_array($p['kind'], ['sling', 'loop'], true)
                && preg_match('/\brov\b|rov\+/i', $p['code'] ?? ''))));
        // 'loop' (a reserve coil, no house) still carries the duct through it, same as a
        // plain unmarked trench point — only 'sling' (an explicit house) ends a duct.
        $ductPoints = array_values(array_filter($points, fn ($p) => in_array($p['kind'], ['trench', 'sling', 'loop'], true)));

        [$trenchNodes, $trenchEdges] = $this->graphBuilder->build(
            $trenchPoints,
            self::NODE_MERGE_M,
            self::CUSTOMER_SPUR_TO_TRENCH_M,
            self::TAGGED_DUCT_GAP_M,
            self::TRENCH_GAP_M,
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
                if ($attrs['color'] !== null || $attrs['tag'] !== null) {
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
        $cabinetRoutingTrenches = $routingTrenches;
        $ducts = $this->dropRouting->process(
            $ducts,
            $routingTrenches,
            $cabinetRoutingTrenches,
            $points,
            array_merge($points, $existingCabinetPoints),
        );

        return ['trenches' => $trenches, 'ducts' => $ducts];
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
            'by_kind' => collect($points)->groupBy('kind')->map->count()->all(),
            'trench_runs' => collect($network['trenches'])->map(fn (array $chain) => [
                'code' => $chain['code'],
                'points' => $chain['points'],
                'length_m' => $chain['length_m'],
                'microduct_type' => null,
                'microduct_count' => 0,
                'path' => $chain['path'],
            ])->values()->all(),
            'trench_total_m' => array_sum(array_column($network['trenches'], 'length_m')),
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
                $cabinetReached = (bool) ($duct['cabinet_reached'] ?? false)
                    || ($binding['cabinet'] !== null && min(...array_map(
                        fn (array $endpoint) => $this->geometry->distanceMeters(
                            (float) $binding['cabinet']->latitude, (float) $binding['cabinet']->longitude,
                            $endpoint[0], $endpoint[1]
                        ),
                        $this->pathEndpoints($duct['path'])
                    )) <= self::DUCT_ENDPOINT_BIND_M);

                return [
                    'key' => $duct['key'],
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
    public function confirm(Project $project, string $contents, string $filename = '', array $cabinetOverrides = []): array
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

        DB::transaction(function () use ($project, $points, $batch, $filename, $cabinetOverrides, &$created): void {
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

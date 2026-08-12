<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\SurveyPoint;
use Illuminate\Support\Collection;
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
        private readonly BranchSyncService $branchSync,
        private readonly SurveyPointParser $parser,
        private readonly SurveyPointClassifier $classifier,
        private readonly SurveyPointCodeNormalizer $codeNormalizer,
        private readonly SurveyImportMaintenanceService $maintenance,
        private readonly SurveyPathGeometryService $pathGeometry,
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

        [$trenchNodes, $trenchEdges] = $this->buildGraph($trenchPoints);
        $trenches = [];
        if (count($trenchEdges) > 0) {
            $trenchPaths = array_map(
                fn (array $chain) => array_map(fn (int $node) => $trenchNodes[$node], $chain['nodes']),
                $this->walkChains($trenchEdges, $trenchNodes, null)
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

        [$ductNodes, $ductEdges, $dropCheckpointNodes] = $this->buildGraph($ductPoints);

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
                foreach ($this->walkHouseDropChains($sub, $dropCheckpointNodes, fn (array $e) => $e['group']) as $chain) {
                    if (count($chain['nodes']) < 2) {
                        continue;
                    }
                    $byCount[$chain['group']][] = [
                        'path' => array_map(fn (int $node) => $ductNodes[$node], $chain['nodes']),
                        'component' => $componentOf[$chain['nodes'][0]] ?? $chain['nodes'][0],
                    ];
                }
            } else {
                foreach ($this->walkChains($sub, $ductNodes, fn (array $e) => $e['group']) as $chain) {
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
                        'label' => $this->ductLabel($attrs, (int) $chainCount),
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

        $ducts = $this->reconstructColoredFlows($ducts, $trenches, $points);
        $ducts = $this->anchorColoredPathsToOdfs($ducts, $points, 5.0);
        $ducts = $this->ensureObservedOdfColorStarts($ducts, $points, 5.0);
        $ducts = $this->distributeOdfBundleCounts($ducts, $points);
        $ducts = $this->connectColoredCountTransitions($ducts, $points, 10.0);
        $ducts = $this->inferImplicitCabinetTags($ducts);
        $ducts = $this->routeTaggedColoredDuctsThroughCabinets($ducts, $points);
        $routingTrenches = array_merge(
            array_map(fn (array $trench) => $trench + ['_routing_source' => 'survey'], $trenches),
            array_map(fn (array $path) => ['path' => $path, '_routing_source' => 'existing'], $existingTrenchPaths)
        );
        $cabinetRoutingTrenches = $routingTrenches;
        $ducts = $this->createImplicitTaggedDrops($ducts, $routingTrenches, $points);
        $ducts = $this->attachDropMetadata($ducts, $points);
        $ducts = $this->routeTaggedDropsThroughTrenches(
            $ducts,
            $cabinetRoutingTrenches,
            array_merge($points, $existingCabinetPoints)
        );
        $ducts = $this->retainTerminalCustomerDrops($ducts, $points);

        return ['trenches' => $trenches, 'ducts' => $ducts];
    }

    /**
     * A serial coloured distribution duct physically enters every surveyed cabinet and
     * the following section leaves from that exact same cabinet coordinate. Survey points
     * normally run along the trench beside the cabinet, so make the short in/out detour
     * explicit in route geometry. Explicit TRANZIT ducts are intentionally excluded.
     */
    private function routeTaggedColoredDuctsThroughCabinets(array $ducts, array $points): array
    {
        $cabinets = array_values(array_filter($points, fn (array $point) => $point['kind'] === 'cabinet'));
        if ($cabinets === []) {
            return $ducts;
        }

        foreach ($ducts as $index => $duct) {
            if (($duct['microduct_type'] ?? null) !== '14/10'
                || ($duct['color'] ?? null) === null
                || ($duct['zo_tag'] ?? null) === null
                || ($duct['transit'] ?? false) === true
                || count($duct['path'] ?? []) < 2) {
                continue;
            }

            foreach ($cabinets as $cabinet) {
                $coordinate = [round((float) $cabinet['lat'], 7), round((float) $cabinet['lng'], 7)];
                $path = $ducts[$index]['path'];
                $lastIndex = count($path) - 1;
                $startDistance = $this->geometry->distanceBetweenPoints($coordinate, $path[0]);
                $endDistance = $this->geometry->distanceBetweenPoints($coordinate, $path[$lastIndex]);

                if ($startDistance <= self::EXISTING_ELEMENT_TOLERANCE_M) {
                    $ducts[$index]['path'][0] = $coordinate;

                    continue;
                }
                if ($endDistance <= self::EXISTING_ELEMENT_TOLERANCE_M) {
                    $ducts[$index]['path'][$lastIndex] = $coordinate;

                    continue;
                }

                $projection = $this->projectPointToPath($coordinate, $path);
                if ($projection['distance_m'] > self::DUCT_ENDPOINT_BIND_M) {
                    continue;
                }

                $segmentIndex = max(1, min((int) $projection['segment_index'], count($path) - 1));
                array_splice($ducts[$index]['path'], $segmentIndex, 0, [$coordinate]);
            }

            $ducts[$index]['path'] = $this->geometry->compactPath($ducts[$index]['path']);
            $ducts[$index]['length_m'] = $this->geometry->polylineLength($ducts[$index]['path']);
        }

        return $ducts;
    }

    /** Split an xN ODF inventory across N separately surveyed outgoing paths. */
    private function distributeOdfBundleCounts(array $ducts, array $points): array
    {
        $odfs = array_values(array_filter($points, fn (array $point) => $point['kind'] === 'odf'));
        foreach ($odfs as $odf) {
            $coordinate = [round((float) $odf['lat'], 7), round((float) $odf['lng'], 7)];
            $groups = [];
            foreach ($ducts as $index => $duct) {
                if (($duct['color'] ?? null) === null || count($duct['path'] ?? []) < 2) {
                    continue;
                }
                if ($duct['path'][0] === $coordinate || end($duct['path']) === $coordinate) {
                    $groups[$duct['microduct_type'].'|'.$duct['color']][] = $index;
                }
            }
            foreach ($groups as $indexes) {
                $inventory = max(array_map(fn (int $index) => (int) $ducts[$index]['microduct_count'], $indexes));
                if ($inventory !== count($indexes)) {
                    continue;
                }
                foreach ($indexes as $index) {
                    $ducts[$index]['microduct_count'] = 1;
                    $ducts[$index]['label'] = $this->ductLabel([
                        'type' => $ducts[$index]['microduct_type'],
                        'color' => $ducts[$index]['color'],
                        'tag' => $ducts[$index]['zo_tag'],
                    ], 1);
                }
            }
        }

        return $ducts;
    }

    /** ODF is a source/terminal node: nearby coloured walk ends must meet it exactly. */
    private function anchorColoredPathsToOdfs(array $ducts, array $points, float $toleranceM): array
    {
        $odfs = array_values(array_filter($points, fn (array $point) => $point['kind'] === 'odf'));
        if ($odfs === []) {
            return $ducts;
        }

        foreach ($ducts as $index => $duct) {
            if (($duct['color'] ?? null) === null || count($duct['path'] ?? []) < 2) {
                continue;
            }
            foreach ([false, true] as $atEnd) {
                $endpoint = $atEnd ? end($ducts[$index]['path']) : $ducts[$index]['path'][0];
                $nearest = null;
                foreach ($odfs as $odf) {
                    $coordinate = [(float) $odf['lat'], (float) $odf['lng']];
                    $distance = $this->geometry->distanceBetweenPoints($endpoint, $coordinate);
                    if ($distance <= $toleranceM && ($nearest === null || $distance < $nearest['distance'])) {
                        $nearest = ['coordinate' => $coordinate, 'distance' => $distance];
                    }
                }
                if ($nearest === null) {
                    continue;
                }
                if ($atEnd) {
                    $ducts[$index]['path'][array_key_last($ducts[$index]['path'])] = $nearest['coordinate'];
                } else {
                    $ducts[$index]['path'][0] = $nearest['coordinate'];
                }
            }
            $ducts[$index]['path'] = $this->geometry->compactPath($ducts[$index]['path']);
            $ducts[$index]['length_m'] = $this->geometry->polylineLength($ducts[$index]['path']);
        }

        return $ducts;
    }

    /**
     * Preserve an explicitly surveyed coloured duct leaving an ODF even if a later
     * disconnected field walk prevented the flow reconstructor from retaining that first
     * short section. Never bridge to a distant branch: only the measured ODF-to-observation
     * stub is materialised here.
     */
    private function ensureObservedOdfColorStarts(array $ducts, array $points, float $toleranceM): array
    {
        $odfs = array_values(array_filter($points, fn (array $point) => $point['kind'] === 'odf'));
        foreach ($odfs as $odf) {
            $odfCoordinate = [round((float) $odf['lat'], 7), round((float) $odf['lng'], 7)];
            foreach ($points as $point) {
                if (($point['kind'] ?? null) !== 'trench'
                    || $this->geometry->distanceBetweenPoints($odfCoordinate, [$point['lat'], $point['lng']]) > $toleranceM) {
                    continue;
                }
                foreach ($this->pointDuctIdentities($point) as $key => $attrs) {
                    if ($attrs['type'] !== '14/10' || $attrs['color'] === null) {
                        continue;
                    }
                    $alreadyAnchored = collect($ducts)->contains(function (array $duct) use ($attrs, $odfCoordinate): bool {
                        if (($duct['microduct_type'] ?? null) !== $attrs['type']
                            || ($duct['color'] ?? null) !== $attrs['color']) {
                            return false;
                        }

                        return collect($this->pathEndpoints($duct['path']))->contains(
                            fn (array $endpoint) => $this->geometry->distanceBetweenPoints($endpoint, $odfCoordinate) <= 0.5
                        );
                    });
                    if ($alreadyAnchored) {
                        continue;
                    }
                    $path = $this->geometry->compactPath([
                        $odfCoordinate,
                        [round((float) $point['lat'], 7), round((float) $point['lng'], 7)],
                    ]);
                    if (count($path) < 2) {
                        continue;
                    }
                    $ducts[] = [
                        'key' => $key,
                        'label' => $this->ductLabel($attrs, (int) $attrs['count']),
                        'microduct_type' => $attrs['type'],
                        'microduct_count' => (int) $attrs['count'],
                        'color' => $attrs['color'],
                        'zo_tag' => $attrs['tag'],
                        'path' => $path,
                        'length_m' => $this->geometry->polylineLength($path),
                    ];
                }
                // Only the closest first trench observation is the ODF exit inventory.
                break;
            }
        }

        return $ducts;
    }

    /**
     * Join survey walks where a coloured bundle changes quantity at a split, e.g.
     * 2x green on the ODF trunk becoming two separate 1x green branches. Keep the
     * paths separate (their counts differ), but give them an exact shared node.
     */
    private function connectColoredCountTransitions(array $ducts, array $points, float $toleranceM): array
    {
        $odfCoordinates = array_values(array_map(
            fn (array $point) => [round((float) $point['lat'], 7), round((float) $point['lng'], 7)],
            array_filter($points, fn (array $point) => $point['kind'] === 'odf')
        ));
        for ($i = 0; $i < count($ducts); $i++) {
            if (($ducts[$i]['color'] ?? null) === null || count($ducts[$i]['path'] ?? []) < 2) {
                continue;
            }
            for ($j = $i + 1; $j < count($ducts); $j++) {
                if (($ducts[$j]['color'] ?? null) !== $ducts[$i]['color']
                    || ($ducts[$j]['microduct_type'] ?? null) !== $ducts[$i]['microduct_type']
                    || (int) $ducts[$j]['microduct_count'] === (int) $ducts[$i]['microduct_count']
                    || count($ducts[$j]['path'] ?? []) < 2) {
                    continue;
                }

                $trunkIndex = (int) $ducts[$i]['microduct_count'] > (int) $ducts[$j]['microduct_count'] ? $i : $j;
                $branchIndex = $trunkIndex === $i ? $j : $i;
                $branchStart = $ducts[$branchIndex]['path'][0];
                $branchEnd = end($ducts[$branchIndex]['path']);
                if (in_array($branchStart, $odfCoordinates, true) || in_array($branchEnd, $odfCoordinates, true)) {
                    continue;
                }
                $best = null;
                foreach ([false, true] as $branchAtEnd) {
                    $endpoint = $branchAtEnd ? end($ducts[$branchIndex]['path']) : $ducts[$branchIndex]['path'][0];
                    $projection = $this->projectPointToPath($endpoint, $ducts[$trunkIndex]['path']);
                    if ($projection['distance_m'] > $toleranceM
                        || ($best !== null && $projection['distance_m'] >= $best['distance_m'])) {
                        continue;
                    }
                    $best = $projection + ['branch_at_end' => $branchAtEnd];
                }
                if ($best === null) {
                    continue;
                }

                $junction = [$best['lat'], $best['lng']];
                $segmentIndex = (int) $best['segment_index'];
                $before = $ducts[$trunkIndex]['path'][$segmentIndex - 1] ?? null;
                $after = $ducts[$trunkIndex]['path'][$segmentIndex] ?? null;
                if ($before !== $junction && $after !== $junction) {
                    array_splice($ducts[$trunkIndex]['path'], $segmentIndex, 0, [$junction]);
                    $ducts[$trunkIndex]['length_m'] = $this->geometry->polylineLength($ducts[$trunkIndex]['path']);
                }

                $branchEndpointIndex = $best['branch_at_end']
                    ? array_key_last($ducts[$branchIndex]['path'])
                    : 0;
                $ducts[$branchIndex]['path'][$branchEndpointIndex] = $junction;
                $ducts[$branchIndex]['path'] = $this->geometry->compactPath($ducts[$branchIndex]['path']);
                $ducts[$branchIndex]['length_m'] = $this->geometry->polylineLength($ducts[$branchIndex]['path']);
            }
        }

        return $ducts;
    }

    /** Project [lat,lng] onto the nearest segment of a plain path array. */
    private function projectPointToPath(array $point, array $path): array
    {
        $best = ['lat' => $point[0], 'lng' => $point[1], 'distance_m' => INF, 'segment_index' => 0];
        for ($i = 1; $i < count($path); $i++) {
            $a = $this->geometry->toCartesian((float) $path[$i - 1][0], (float) $path[$i - 1][1], (float) $point[0], (float) $point[1]);
            $b = $this->geometry->toCartesian((float) $path[$i][0], (float) $path[$i][1], (float) $point[0], (float) $point[1]);
            $dx = $b['x'] - $a['x'];
            $dy = $b['y'] - $a['y'];
            $lengthSquared = max(0.000001, $dx ** 2 + $dy ** 2);
            $t = max(0, min(1, (-$a['x'] * $dx - $a['y'] * $dy) / $lengthSquared));
            $x = $a['x'] + $dx * $t;
            $y = $a['y'] + $dy * $t;
            $distance = sqrt($x ** 2 + $y ** 2);
            if ($distance >= $best['distance_m']) {
                continue;
            }
            $best = [
                'lat' => round((float) $point[0] + $y / 111320, 7),
                'lng' => round((float) $point[1] + $x / (111320 * cos(deg2rad((float) $point[0]))), 7),
                'distance_m' => $distance,
                'segment_index' => $i,
            ];
        }

        return $best;
    }

    /**
     * Descriptions are observations, not a full inventory at every point. Rebuild each
     * coloured 14/10 flow over the physical trench graph between consecutive observations,
     * so an intervening point that mentions only another colour does not cut this duct.
     */
    private function reconstructColoredFlows(array $ducts, array $trenches, array $points): array
    {
        $nodes = [];
        $adjacency = [];
        $nodeId = function (array $point) use (&$nodes): string {
            $key = sprintf('%.7f,%.7f', $point[0], $point[1]);
            $nodes[$key] = $point;

            return $key;
        };
        foreach ($trenches as $trench) {
            for ($i = 1; $i < count($trench['path']); $i++) {
                $a = $nodeId($trench['path'][$i - 1]);
                $b = $nodeId($trench['path'][$i]);
                if ($a === $b) {
                    continue;
                }
                $weight = $this->geometry->distanceBetweenPoints($nodes[$a], $nodes[$b]);
                $adjacency[$a][$b] = $weight;
                $adjacency[$b][$a] = $weight;
            }
        }
        if (count($nodes) < 2) {
            return $ducts;
        }

        $observations = [];
        foreach ($points as $pointIndex => $point) {
            if ($point['kind'] !== 'trench') {
                continue;
            }
            foreach ($this->pointDuctIdentities($point) as $key => $attrs) {
                if ($attrs['type'] !== '14/10' || $attrs['color'] === null) {
                    continue;
                }
                $observations[$key]['attrs'] = $attrs;
                $observations[$key]['count'] = max($observations[$key]['count'] ?? 1, (int) $attrs['count']);
                $observations[$key]['points'][] = [
                    'coordinate' => [$point['lat'], $point['lng']],
                    'source_index' => $pointIndex,
                    'count' => max(1, (int) $attrs['count']),
                ];
            }
        }

        $nearestNode = function (array $point) use (&$nodes): ?string {
            $bestKey = null;
            $bestDistance = INF;
            foreach ($nodes as $key => $candidate) {
                $distance = $this->geometry->distanceBetweenPoints($point, $candidate);
                if ($distance < $bestDistance) {
                    $bestKey = $key;
                    $bestDistance = $distance;
                }
            }

            return $bestDistance <= self::DUCT_ENDPOINT_BIND_M ? $bestKey : null;
        };

        $shortestPath = function (string $start, string $target) use (&$adjacency): array {
            if ($start === $target) {
                return [$start];
            }
            $distance = [$start => 0.0];
            $previous = [];
            $queue = [[$start, 0.0]];
            while ($queue) {
                usort($queue, fn ($a, $b) => $a[1] <=> $b[1]);
                [$current, $currentDistance] = array_shift($queue);
                if ($current === $target) {
                    break;
                }
                if ($currentDistance > ($distance[$current] ?? INF)) {
                    continue;
                }
                foreach ($adjacency[$current] ?? [] as $next => $weight) {
                    $candidate = $currentDistance + $weight;
                    if ($candidate < ($distance[$next] ?? INF)) {
                        $distance[$next] = $candidate;
                        $previous[$next] = $current;
                        $queue[] = [$next, $candidate];
                    }
                }
            }
            if (! isset($distance[$target])) {
                return [];
            }
            $path = [$target];
            while (end($path) !== $start) {
                $path[] = $previous[end($path)];
            }

            return array_reverse($path);
        };

        $rebuilt = [];
        $rebuiltKeys = [];
        foreach ($observations as $key => $observation) {
            $observed = [];
            foreach ($observation['points'] as $entry) {
                $node = $nearestNode($entry['coordinate']);
                if ($node === null || ($observed !== [] && end($observed)['node'] === $node)) {
                    continue;
                }
                $observed[] = [
                    'node' => $node,
                    'source_index' => $entry['source_index'],
                    'count' => $entry['count'],
                ];
            }
            if (count($observed) < 2) {
                continue;
            }

            $flowEdges = [];
            for ($i = 1; $i < count($observed); $i++) {
                $previousObservation = $observed[$i - 1];
                $currentObservation = $observed[$i];
                $fromIndex = min($previousObservation['source_index'], $currentObservation['source_index']);
                $toIndex = max($previousObservation['source_index'], $currentObservation['source_index']);
                $hasOtherColourDetour = false;
                for ($sourceIndex = $fromIndex + 1; $sourceIndex < $toIndex; $sourceIndex++) {
                    if (($points[$sourceIndex]['kind'] ?? null) !== 'trench') {
                        continue;
                    }
                    $intermediateIdentities = $this->pointDuctIdentities($points[$sourceIndex]);
                    $hasColouredDuct = collect($intermediateIdentities)->contains(
                        fn (array $identity) => $identity['type'] === '14/10' && $identity['color'] !== null
                    );
                    if ($hasColouredDuct && ! isset($intermediateIdentities[$key])) {
                        $hasOtherColourDetour = true;
                        break;
                    }
                }

                // Look ahead: ALL colours -> BLUE only -> ALL colours means blue takes
                // the cabinet spur, while the missing colours continue directly between
                // their surrounding observations.
                $directDistance = $this->geometry->distanceBetweenPoints(
                    $nodes[$previousObservation['node']],
                    $nodes[$currentObservation['node']]
                );
                $path = $hasOtherColourDetour && $directDistance <= self::TRENCH_GAP_M
                    ? [$previousObservation['node'], $currentObservation['node']]
                    : $shortestPath($previousObservation['node'], $currentObservation['node']);
                $edgeCount = min($previousObservation['count'], $currentObservation['count']);
                for ($j = 1; $j < count($path); $j++) {
                    $a = $path[$j - 1];
                    $b = $path[$j];
                    $edgeKey = strcmp($a, $b) < 0 ? $a.'|'.$b : $b.'|'.$a;
                    if (! isset($flowEdges[$edgeKey])) {
                        $flowEdges[$edgeKey] = ['a' => $a, 'b' => $b, 'count' => $edgeCount];
                    } else {
                        $flowEdges[$edgeKey]['count'] = max($flowEdges[$edgeKey]['count'], $edgeCount);
                    }
                }
            }
            if (count($flowEdges) === 0) {
                continue;
            }

            $attrs = $observation['attrs'];
            $rebuiltStart = count($rebuilt);
            $flowNodeIds = [];
            $flowNodes = [];
            $integerEdges = [];
            foreach ($flowEdges as $edge) {
                foreach ([$edge['a'], $edge['b']] as $nodeKey) {
                    if (! isset($flowNodeIds[$nodeKey])) {
                        $flowNodeIds[$nodeKey] = count($flowNodeIds);
                        $flowNodes[$flowNodeIds[$nodeKey]] = $nodes[$nodeKey];
                    }
                }
                $integerEdges[] = [
                    'a' => $flowNodeIds[$edge['a']],
                    'b' => $flowNodeIds[$edge['b']],
                    'count' => $edge['count'],
                ];
            }
            foreach ($this->walkChains($integerEdges, $flowNodes, fn (array $edge) => $edge['count']) as $chain) {
                if (count($chain['nodes']) < 2) {
                    continue;
                }
                $path = array_map(fn (int $node) => $flowNodes[$node], $chain['nodes']);
                $chainCount = $integerEdges[$chain['edges'][0]]['count'];
                $rebuilt[] = [
                    'key' => $key,
                    'label' => $this->ductLabel($attrs, $chainCount),
                    'microduct_type' => '14/10',
                    'microduct_count' => $chainCount,
                    'color' => $attrs['color'],
                    'zo_tag' => $attrs['tag'],
                    'transit' => (bool) ($attrs['transit'] ?? false),
                    'path' => $path,
                    'length_m' => $this->geometry->polylineLength($path),
                ];
            }
            $originalMaxPoints = collect($ducts)->where('key', $key)->map(fn (array $duct) => count($duct['path']))->max() ?? 0;
            $rebuiltMaxPoints = collect(array_slice($rebuilt, $rebuiltStart))->map(fn (array $duct) => count($duct['path']))->max() ?? 0;
            if ($originalMaxPoints > $rebuiltMaxPoints) {
                array_splice($rebuilt, $rebuiltStart);

                continue;
            }
            $rebuiltKeys[$key] = true;
        }

        $untouched = array_values(array_filter($ducts, fn (array $duct) => ! isset($rebuiltKeys[$duct['key']])));

        return array_merge($untouched, $rebuilt);
    }

    /**
     * Field exports often record an "Izvod 10/8 -ZO n" as one point on the main dig,
     * without repeating 10/8 on the shared-trench coordinates. Materialise that point as
     * a drop stub; routeTaggedDropsThroughTrenches() then completes it to the named ZO.
     */
    private function createImplicitTaggedDrops(array $ducts, array $trenches, array $points): array
    {
        $trenchVertices = collect($trenches)->flatMap(fn (array $trench) => $trench['path'])->values()->all();
        if (count($trenchVertices) === 0) {
            return $ducts;
        }
        $terminalByPoint = collect($points)->where('kind', 'sling')->keyBy('point_no');

        foreach (array_filter($points, fn (array $point) => $point['kind'] === 'sling'
            && $point['microduct_type'] === '10/8' && $point['zo_tag'] !== null) as $terminal) {
            // The same physical house/SLINGA is sometimes measured twice only a few
            // centimetres apart. It is still one customer and must produce one route.
            $duplicateTerminal = collect($ducts)->contains(function (array $duct) use ($terminal, $terminalByPoint): bool {
                if ($duct['microduct_type'] !== '10/8'
                    || ($duct['zo_tag'] ?? null) !== $terminal['zo_tag']
                    || ! isset($duct['_terminal_point'])) {
                    return false;
                }
                $representedTerminal = $terminalByPoint->get((int) $duct['_terminal_point']);
                if ($representedTerminal === null) {
                    return false;
                }

                return $this->geometry->distanceMeters(
                    $terminal['lat'], $terminal['lng'],
                    $representedTerminal['lat'], $representedTerminal['lng']
                ) <= self::NODE_MERGE_M;
            });
            if ($duplicateTerminal) {
                continue;
            }

            $representedIndex = null;
            foreach ($ducts as $ductIndex => $duct) {
                if ($duct['microduct_type'] !== '10/8') {
                    continue;
                }
                if (isset($duct['_terminal_point'])) {
                    continue;
                }
                foreach ($this->pathEndpoints($duct['path']) as $endpoint) {
                    if ($this->geometry->distanceMeters($terminal['lat'], $terminal['lng'], $endpoint[0], $endpoint[1]) <= self::EXISTING_ELEMENT_TOLERANCE_M) {
                        $representedIndex = $ductIndex;
                        break 2;
                    }
                }
            }
            if ($representedIndex !== null) {
                $ducts[$representedIndex] = $this->snapDuctEndpointToTerminal($ducts[$representedIndex], $terminal);
                $ducts[$representedIndex]['_terminal_point'] = $terminal['point_no'];
                $ducts[$representedIndex]['house_ref'] = $terminal['house_ref'] ?? null;
                $ducts[$representedIndex]['prepared_sling'] = true;

                continue;
            }

            $nearest = null;
            $nearestDistance = INF;
            foreach ($trenchVertices as $vertex) {
                $distance = $this->geometry->distanceMeters($terminal['lat'], $terminal['lng'], $vertex[0], $vertex[1]);
                if ($distance < $nearestDistance) {
                    $nearest = $vertex;
                    $nearestDistance = $distance;
                }
            }
            if ($nearest === null || $nearestDistance > self::DUCT_ENDPOINT_BIND_M) {
                continue;
            }

            $ducts[] = [
                'key' => '10/8|zo:'.$terminal['zo_tag'].'|point:'.$terminal['point_no'],
                'label' => $this->ductLabel([
                    'type' => '10/8',
                    'color' => $terminal['colors'][0] ?? null,
                    'tag' => $terminal['zo_tag'],
                ], max(1, (int) $terminal['microduct_count'])).' (T'.$terminal['point_no'].')',
                'microduct_type' => '10/8',
                'microduct_count' => max(1, (int) $terminal['microduct_count']),
                'color' => $terminal['colors'][0] ?? null,
                'zo_tag' => $terminal['zo_tag'],
                'path' => [[$terminal['lat'], $terminal['lng']], $nearest],
                'length_m' => $nearestDistance,
                '_terminal_point' => $terminal['point_no'],
                'house_ref' => $terminal['house_ref'] ?? null,
                'prepared_sling' => true,
            ];
        }

        return $ducts;
    }

    /** Attach the named house and distinguish a prepared SLINGA endpoint from a real house point. */
    private function attachDropMetadata(array $ducts, array $points): array
    {
        $terminals = array_values(array_filter($points, fn (array $point) => $point['kind'] === 'sling'));
        $terminalByPoint = collect($terminals)->keyBy('point_no');
        $assignedTerminals = [];

        // Explicit matches created during graph reconstruction always win.
        foreach ($ducts as &$duct) {
            $duct['house_ref'] ??= null;
            $duct['prepared_sling'] ??= false;
            if (isset($duct['_terminal_point'])) {
                $terminal = $terminalByPoint->get((int) $duct['_terminal_point']);
                if ($terminal !== null) {
                    $duct['house_ref'] = $terminal['house_ref'] ?? null;
                    $duct['prepared_sling'] = (bool) ($terminal['prepared_sling'] ?? false);
                    $assignedTerminals[(int) $terminal['point_no']] = true;
                }
            }
        }
        unset($duct);

        // A nearby endpoint is only a fallback. Assign each terminal to ONE closest
        // unclaimed duct; otherwise neighbouring distribution pieces become duplicate
        // house drops and draw triangles between two homes.
        foreach ($terminals as $terminal) {
            if (isset($assignedTerminals[(int) $terminal['point_no']])) {
                continue;
            }
            $bestIndex = null;
            $bestDistance = INF;
            foreach ($ducts as $index => $duct) {
                if (($duct['prepared_sling'] ?? false)
                    || ($duct['microduct_type'] ?? null) !== '10/8'
                    || (($terminal['zo_tag'] ?? null) !== null
                        && ($duct['zo_tag'] ?? null) !== $terminal['zo_tag'])) {
                    continue;
                }
                $nearEndpoint = min(...array_map(
                    fn (array $endpoint) => $this->geometry->distanceMeters($terminal['lat'], $terminal['lng'], $endpoint[0], $endpoint[1]),
                    $this->pathEndpoints($duct['path'])
                ));
                if ($nearEndpoint <= self::EXISTING_ELEMENT_TOLERANCE_M && $nearEndpoint < $bestDistance) {
                    $bestDistance = $nearEndpoint;
                    $bestIndex = $index;
                }
            }
            if ($bestIndex !== null) {
                $ducts[$bestIndex] = $this->snapDuctEndpointToTerminal($ducts[$bestIndex], $terminal);
                $ducts[$bestIndex]['_terminal_point'] = $terminal['point_no'];
                $ducts[$bestIndex]['house_ref'] = $terminal['house_ref'] ?? null;
                $ducts[$bestIndex]['prepared_sling'] = (bool) ($terminal['prepared_sling'] ?? false);
            }
        }

        return $ducts;
    }

    /** Make a customer route physically terminate at its own surveyed house point. */
    private function snapDuctEndpointToTerminal(array $duct, array $terminal): array
    {
        if (count($duct['path'] ?? []) < 2) {
            return $duct;
        }

        $terminalPoint = [round((float) $terminal['lat'], 7), round((float) $terminal['lng'], 7)];
        $lastIndex = count($duct['path']) - 1;
        $startDistance = $this->geometry->distanceBetweenPoints($terminalPoint, $duct['path'][0]);
        $endDistance = $this->geometry->distanceBetweenPoints($terminalPoint, $duct['path'][$lastIndex]);
        $duct['path'][$startDistance <= $endDistance ? 0 : $lastIndex] = $terminalPoint;
        $duct['path'] = $this->geometry->compactPath($duct['path']);
        $duct['length_m'] = $this->geometry->polylineLength($duct['path']);

        return $duct;
    }

    /**
     * A tagged 10/8 customer branch may only be surveyed from the house/SLINGA to the
     * shared trench. Complete it over the physical trench graph to the named ZO without
     * requiring the surveyor to repeat "10/8 ZO n" on every shared-trench point.
     */
    private function routeTaggedDropsThroughTrenches(array $ducts, array $trenches, array $points): array
    {
        $cabinets = collect($points)->where('kind', 'cabinet')->values();
        if ($cabinets->isEmpty() || count($trenches) === 0) {
            return $ducts;
        }

        $customerTerminals = array_values(array_filter($points, fn (array $point) => in_array($point['kind'] ?? null, ['sling', 'loop'], true)
            && (($point['kind'] ?? null) === 'sling' || ($point['microduct_type'] ?? null) === '10/8')
        ));
        $nodes = [];
        $adjacency = [];
        $terminalGraphNodes = [];
        $terminalNumbersAt = function (array $point) use ($customerTerminals): array {
            $numbers = [];
            foreach ($customerTerminals as $terminalPoint) {
                // Paths contain both a house and, sometimes, a separate trench reading
                // only centimetres away. Match the actual rounded survey coordinate,
                // not a proximity radius that would turn that trench point into a house.
                if (round((float) $terminalPoint['lat'], 7) === round((float) $point[0], 7)
                    && round((float) $terminalPoint['lng'], 7) === round((float) $point[1], 7)) {
                    $numbers[] = (int) $terminalPoint['point_no'];
                }
            }

            return $numbers;
        };
        $nodeId = function (array $point, bool $detectTerminal = true) use (&$nodes, &$terminalGraphNodes, $terminalNumbersAt): string {
            $pointTerminalNumbers = $detectTerminal ? $terminalNumbersAt($point) : [];
            foreach ($nodes as $existingKey => $existingPoint) {
                $existingIsTerminal = isset($terminalGraphNodes[$existingKey]);
                $pointIsTerminal = $pointTerminalNumbers !== [];
                if ($existingIsTerminal xor $pointIsTerminal) {
                    continue;
                }
                $mergeDistance = $pointIsTerminal ? 0.5 : self::NODE_MERGE_M;
                if ($this->geometry->distanceBetweenPoints($point, $existingPoint) <= $mergeDistance) {
                    if ($pointIsTerminal) {
                        $terminalGraphNodes[$existingKey] = array_values(array_unique(array_merge(
                            $terminalGraphNodes[$existingKey],
                            $pointTerminalNumbers
                        )));
                    }

                    return $existingKey;
                }
            }
            $key = sprintf('%.7f,%.7f', $point[0], $point[1]);
            while (isset($nodes[$key])) {
                $key .= '#';
            }
            $nodes[$key] = $point;
            if ($pointTerminalNumbers !== []) {
                $terminalGraphNodes[$key] = $pointTerminalNumbers;
            }

            return $key;
        };
        $addPathToGraph = function (array $path, bool $detectTerminals = true) use (&$nodes, &$adjacency, $nodeId): void {
            for ($i = 1; $i < count($path); $i++) {
                $a = $nodeId($path[$i - 1], $detectTerminals);
                $b = $nodeId($path[$i], $detectTerminals);
                if ($a === $b) {
                    continue;
                }
                $weight = $this->geometry->distanceBetweenPoints($nodes[$a], $nodes[$b]);
                $adjacency[$a][] = [$b, $weight];
                $adjacency[$b][] = [$a, $weight];
            }
        };

        // A freshly surveyed customer spur commonly ends on the middle of an older
        // main-trench segment. Its endpoint therefore has no matching old vertex. Snap
        // that endpoint to the segment projection and split the old graph edge there,
        // so routing continues along the already mapped trench instead of drawing a
        // direct house-to-cabinet shortcut.
        $existingPaths = array_values(array_filter(
            $trenches,
            fn (array $trench) => ($trench['_routing_source'] ?? null) === 'existing'
                && count($trench['path'] ?? []) >= 2
        ));
        $snapPaths = [];
        $terminalSnapEdges = [];
        if ($existingPaths !== []) {
            foreach ($trenches as $trench) {
                if (($trench['_routing_source'] ?? null) === 'existing' || count($trench['path'] ?? []) < 2) {
                    continue;
                }
                $surveyPath = array_values($trench['path']);
                foreach ([$surveyPath[0], end($surveyPath)] as $endpoint) {
                    $isTerminalEndpoint = $terminalNumbersAt($endpoint) !== [];
                    foreach ($existingPaths as $existingTrench) {
                        $projection = $this->projectPointToPath($endpoint, $existingTrench['path']);
                        $snapLimit = $isTerminalEndpoint ? 0.5 : 5.0;
                        if ($projection['distance_m'] > $snapLimit || $projection['segment_index'] < 1) {
                            continue;
                        }
                        $projectionPoint = [$projection['lat'], $projection['lng']];
                        $segmentIndex = (int) $projection['segment_index'];
                        $snapPaths[] = [
                            $existingTrench['path'][$segmentIndex - 1],
                            $projectionPoint,
                            $existingTrench['path'][$segmentIndex],
                        ];
                        if ($isTerminalEndpoint) {
                            $terminalSnapEdges[] = [$endpoint, $projectionPoint];
                        } else {
                            $snapPaths[] = [$endpoint, $projectionPoint];
                        }
                    }
                }
            }
        }
        foreach ($trenches as $trench) {
            $addPathToGraph(
                $trench['path'],
                ($trench['_routing_source'] ?? null) !== 'existing'
            );
        }
        foreach ($snapPaths as $snapPath) {
            $addPathToGraph($snapPath, false);
        }
        foreach ($terminalSnapEdges as [$terminalEndpoint, $projectionPoint]) {
            $a = $nodeId($terminalEndpoint, true);
            $b = $nodeId($projectionPoint, false);
            if ($a === $b) {
                continue;
            }
            $weight = $this->geometry->distanceBetweenPoints($nodes[$a], $nodes[$b]);
            $adjacency[$a][] = [$b, $weight];
            $adjacency[$b][] = [$a, $weight];
        }

        $trenchNodes = $nodes;
        $trenchAdjacency = $adjacency;

        $pathBetweenProjections = static function (array $path, array $start, array $end): array {
            $reverse = $start['segment_index'] > $end['segment_index'];
            $from = $reverse ? $end : $start;
            $to = $reverse ? $start : $end;
            $slice = [[$from['lat'], $from['lng']]];
            for ($i = (int) $from['segment_index']; $i < (int) $to['segment_index']; $i++) {
                $slice[] = $path[$i];
            }
            $slice[] = [$to['lat'], $to['lng']];

            return $reverse ? array_reverse($slice) : $slice;
        };

        foreach ($ducts as &$duct) {
            if ($duct['microduct_type'] !== '10/8' || $duct['zo_tag'] === null || count($duct['path']) < 2) {
                continue;
            }
            $duct['cabinet_reached'] = false;
            $cabinet = $cabinets->first(fn ($point) => $this->cabinetTag($point['code']) === $duct['zo_tag']);
            if (! $cabinet) {
                continue;
            }

            // Every customer drop is routed independently over the physical trench graph.
            // Never add peer 10/8 drops here: doing so lets one house use another house's
            // private branch as a shortcut and creates loops/crossovers at shared forks.
            $nodes = $trenchNodes;
            $adjacency = $trenchAdjacency;

            $terminal = isset($duct['_terminal_point'])
                ? collect($points)->firstWhere('point_no', (int) $duct['_terminal_point'])
                : null;
            if ($terminal === null) {
                continue;
            }
            $blockedTerminalNodes = [];
            foreach ($terminalGraphNodes as $key => $pointNumbers) {
                if (! in_array((int) $terminal['point_no'], $pointNumbers, true)) {
                    $blockedTerminalNodes[$key] = true;
                }
            }
            $terminalCoordinate = [round((float) $terminal['lat'], 7), round((float) $terminal['lng'], 7)];
            // A cluster of approximate house endpoints can place another house between
            // this terminal and the first real trench observation. Start each customer
            // independently at the nearest NON-terminal trench node; customer points
            // must never be used as somebody else's shared corridor.
            $joinNode = null;
            $joinDistance = INF;
            foreach ($nodes as $candidateKey => $candidatePoint) {
                if (isset($terminalGraphNodes[$candidateKey])) {
                    continue;
                }
                $candidateDistance = $this->geometry->distanceBetweenPoints($terminalCoordinate, $candidatePoint);
                if ($candidateDistance < $joinDistance) {
                    $joinNode = $candidateKey;
                    $joinDistance = $candidateDistance;
                }
            }
            if ($joinNode === null || $joinDistance > self::DUCT_ENDPOINT_BIND_M) {
                continue;
            }

            $distance = [$joinNode => 0.0];
            $previous = [];
            $queue = [[$joinNode, 0.0]];
            while ($queue) {
                usort($queue, fn ($a, $b) => $a[1] <=> $b[1]);
                [$current, $currentDistance] = array_shift($queue);
                if ($currentDistance > ($distance[$current] ?? INF)) {
                    continue;
                }
                foreach ($adjacency[$current] ?? [] as [$next, $weight]) {
                    if (isset($blockedTerminalNodes[$next])) {
                        continue;
                    }
                    $candidate = $currentDistance + $weight;
                    if ($candidate < ($distance[$next] ?? INF)) {
                        $distance[$next] = $candidate;
                        $previous[$next] = $current;
                        $queue[] = [$next, $candidate];
                    }
                }
            }
            // A cabinet can sit beside two disconnected digs. Pick the closest cabinet
            // access point only among nodes actually reachable from this customer branch.
            // Compare the COMPLETE route cost, not only the node-to-cabinet gap. Choosing
            // the geometrically closest node alone can send a drop past the cabinet down
            // a side branch and then back up (a visible U-turn/spike).
            $targetNode = null;
            $targetDistance = INF;
            $targetScore = INF;
            $fallbackTargetNode = null;
            $fallbackTargetDistance = INF;
            foreach ($distance as $reachableNode => $distanceFromTerminal) {
                if (isset($terminalGraphNodes[$reachableNode])) {
                    continue;
                }
                $candidateDistance = $this->geometry->distanceBetweenPoints(
                    [$cabinet['lat'], $cabinet['lng']],
                    $nodes[$reachableNode]
                );
                if ($candidateDistance > self::DUCT_ENDPOINT_BIND_M) {
                    continue;
                }
                if ($candidateDistance < $fallbackTargetDistance) {
                    $fallbackTargetNode = $reachableNode;
                    $fallbackTargetDistance = $candidateDistance;
                }
                // Only treat a graph node as an alternative cabinet entrance when the
                // trench is genuinely beside the cabinet. A larger allowance here would
                // cut diagonally from an earlier point instead of following the survey.
                if ($candidateDistance > 10.0) {
                    continue;
                }
                $candidateScore = $distanceFromTerminal + $candidateDistance;
                if ($candidateScore < $targetScore) {
                    $targetNode = $reachableNode;
                    $targetDistance = $candidateDistance;
                    $targetScore = $candidateScore;
                }
            }
            if ($targetNode === null) {
                $targetNode = $fallbackTargetNode;
                $targetDistance = $fallbackTargetDistance;
            }
            if ($targetNode === null || $targetDistance > self::DUCT_ENDPOINT_BIND_M) {
                // The new spur may touch the middle of a saved main route whose own graph
                // is split elsewhere. Finish explicitly as: house -> surveyed spur ->
                // projection on saved main -> saved main -> assigned ZO.
                $bestCorridor = null;
                $cabinetCoordinate = [(float) $cabinet['lat'], (float) $cabinet['lng']];
                foreach ($existingPaths as $existingTrench) {
                    $cabinetProjection = $this->projectPointToPath($cabinetCoordinate, $existingTrench['path']);
                    if ($cabinetProjection['distance_m'] > self::DUCT_ENDPOINT_BIND_M) {
                        continue;
                    }
                    foreach ($distance as $reachableNode => $distanceFromTerminal) {
                        if (isset($terminalGraphNodes[$reachableNode])) {
                            continue;
                        }
                        $joinProjection = $this->projectPointToPath($nodes[$reachableNode], $existingTrench['path']);
                        if ($joinProjection['distance_m'] > 5.0) {
                            continue;
                        }
                        $corridorPath = $pathBetweenProjections(
                            $existingTrench['path'],
                            $joinProjection,
                            $cabinetProjection
                        );
                        $score = $distanceFromTerminal
                            + $joinProjection['distance_m']
                            + $this->geometry->polylineLength($corridorPath)
                            + $cabinetProjection['distance_m'];
                        if ($bestCorridor === null || $score < $bestCorridor['score']) {
                            $bestCorridor = [
                                'score' => $score,
                                'join_node' => $reachableNode,
                                'join_projection' => [$joinProjection['lat'], $joinProjection['lng']],
                                'corridor_path' => $corridorPath,
                                'cabinet_projection' => [$cabinetProjection['lat'], $cabinetProjection['lng']],
                            ];
                        }
                    }
                }
                if ($bestCorridor !== null) {
                    $keys = [$bestCorridor['join_node']];
                    while (end($keys) !== $joinNode) {
                        $keys[] = $previous[end($keys)];
                    }
                    $surveyedPath = array_map(fn (string $key) => $nodes[$key], array_reverse($keys));
                    $fullPath = [$terminalCoordinate];
                    if ($surveyedPath !== []
                        && $this->geometry->distanceBetweenPoints($terminalCoordinate, $surveyedPath[0]) <= 0.5) {
                        array_shift($surveyedPath);
                    }
                    $fullPath = array_merge($fullPath, $surveyedPath);
                    if ($this->geometry->distanceBetweenPoints(end($fullPath), $bestCorridor['join_projection']) > 0.5) {
                        $fullPath[] = $bestCorridor['join_projection'];
                    }
                    $fullPath = array_merge($fullPath, $bestCorridor['corridor_path']);
                    if ($this->geometry->distanceBetweenPoints(end($fullPath), $cabinetCoordinate) > 0.5) {
                        $fullPath[] = $cabinetCoordinate;
                    }
                    $duct['path'] = $this->geometry->compactPath($fullPath);
                    $duct['length_m'] = $this->geometry->polylineLength($duct['path']);
                    $duct['cabinet_reached'] = true;
                    $duct['routed_via_trench'] = true;
                }

                // If this approximate house endpoint belongs to a cluster whose short
                // surveyed spur is disconnected from the main graph, join the closest
                // already-proven route for the SAME ZO. The peer route represents the
                // shared physical trench, not another customer's private shortcut.
                if (! ($duct['cabinet_reached'] ?? false)) {
                    $bestPeer = null;
                    foreach ($ducts as $peer) {
                        if (! ($peer['cabinet_reached'] ?? false)
                            || ($peer['microduct_type'] ?? null) !== '10/8'
                            || ($peer['zo_tag'] ?? null) !== $duct['zo_tag']
                            || count($peer['path'] ?? []) < 2) {
                            continue;
                        }
                        $projection = $this->projectPointToPath($terminalCoordinate, $peer['path']);
                        if ($projection['distance_m'] > self::CUSTOMER_SPUR_TO_TRENCH_M
                            || ($bestPeer !== null && $projection['distance_m'] >= $bestPeer['distance_m'])) {
                            continue;
                        }
                        $bestPeer = $projection + ['path' => $peer['path']];
                    }
                    if ($bestPeer !== null) {
                        $peerPath = $bestPeer['path'];
                        $projectionPoint = [$bestPeer['lat'], $bestPeer['lng']];
                        $segmentIndex = max(1, min((int) $bestPeer['segment_index'], count($peerPath) - 1));
                        $cabinetCoordinate = [(float) $cabinet['lat'], (float) $cabinet['lng']];
                        $cabinetAtStart = $this->geometry->distanceBetweenPoints($peerPath[0], $cabinetCoordinate)
                            <= $this->geometry->distanceBetweenPoints(end($peerPath), $cabinetCoordinate);
                        $sharedPath = $cabinetAtStart
                            ? array_merge([$projectionPoint], array_reverse(array_slice($peerPath, 0, $segmentIndex)))
                            : array_merge([$projectionPoint], array_slice($peerPath, $segmentIndex));
                        $fullPath = array_merge([$terminalCoordinate], $sharedPath);
                        if ($this->geometry->distanceBetweenPoints(end($fullPath), $cabinetCoordinate) > 0.5) {
                            $fullPath[] = $cabinetCoordinate;
                        }
                        $duct['path'] = $this->geometry->compactPath($fullPath);
                        $duct['length_m'] = $this->geometry->polylineLength($duct['path']);
                        $duct['cabinet_reached'] = true;
                        $duct['routed_via_trench'] = true;
                    }
                }

                continue;
            }
            // Build one clean route through the physical trench graph. The source walk's
            // ordering is irrelevant here and peer terminal nodes cannot become shortcuts.
            $keys = [$targetNode];
            while (end($keys) !== $joinNode) {
                $keys[] = $previous[end($keys)];
            }
            $mainPath = array_map(fn ($key) => $nodes[$key], array_reverse($keys));
            $fullPath = [$terminalCoordinate];
            if ($mainPath !== [] && $this->geometry->distanceBetweenPoints($terminalCoordinate, $mainPath[0]) <= 0.5) {
                array_shift($mainPath);
            }
            $fullPath = array_merge($fullPath, $mainPath);
            $cabinetPoint = [(float) $cabinet['lat'], (float) $cabinet['lng']];
            if ($this->geometry->distanceBetweenPoints(end($fullPath), $cabinetPoint) > 0.5) {
                $fullPath[] = $cabinetPoint;
            }
            $duct['path'] = $this->geometry->compactPath($fullPath);
            $duct['length_m'] = $this->geometry->polylineLength($duct['path']);
            $duct['cabinet_reached'] = true;
            $duct['routed_via_trench'] = true;
        }
        unset($duct);

        return $ducts;
    }

    /**
     * A tagged 10/8 network is a set of customer routes, not a collection of every
     * intermediate surveyed fragment. Once at least one house/loop terminal exists for
     * a ZO, keep the complete terminal-to-ZO routes and discard the helper fragments used
     * to reconstruct them. This makes all displayed routes start at customers and merge
     * toward the named cabinet.
     */
    private function retainTerminalCustomerDrops(array $ducts, array $points): array
    {
        $loopPoints = array_values(array_filter(
            $points,
            fn (array $point) => ($point['kind'] ?? null) === 'loop'
        ));
        $terminalIndexes = [];
        $terminalTags = [];

        foreach ($ducts as $index => $duct) {
            if (($duct['microduct_type'] ?? null) !== '10/8' || ($duct['zo_tag'] ?? null) === null) {
                continue;
            }

            $isTerminalRoute = isset($duct['_terminal_point'])
                || (bool) ($duct['prepared_sling'] ?? false)
                || filled($duct['house_ref'] ?? null);

            if (! $isTerminalRoute) {
                foreach ($loopPoints as $loop) {
                    if (($loop['zo_tag'] ?? null) !== null && $loop['zo_tag'] !== $duct['zo_tag']) {
                        continue;
                    }
                    foreach ($this->pathEndpoints($duct['path']) as $endpoint) {
                        if ($this->geometry->distanceMeters(
                            $loop['lat'], $loop['lng'], $endpoint[0], $endpoint[1]
                        ) <= self::NODE_MERGE_M) {
                            $isTerminalRoute = true;
                            break 2;
                        }
                    }
                }
            }

            if ($isTerminalRoute) {
                $terminalIndexes[$index] = true;
                $terminalTags[(string) $duct['zo_tag']] = true;
            }
        }

        return array_values(array_filter(
            $ducts,
            function (array $duct, int $index) use ($terminalIndexes, $terminalTags): bool {
                if (($duct['microduct_type'] ?? null) !== '10/8' || ($duct['zo_tag'] ?? null) === null) {
                    return true;
                }
                if (! isset($terminalTags[(string) $duct['zo_tag']])) {
                    return true;
                }

                return isset($terminalIndexes[$index]);
            },
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * Build a graph from ordered survey points by merging nearby nodes and
     * creating edges between consecutive points in the original walk.
     *
     * @return array{0: array<int,array<float,float>>,1: array,2: array<int,bool>} nodes, edges,
     *                                                                             and the subset of node indices that are a house or reserve loop ('sling'/'loop')
     */
    private function buildGraph(array $points): array
    {
        $count = count($points);
        if ($count < 2) {
            return [[], [], []];
        }

        $parent = range(0, $count - 1);
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };

        $spatialBuckets = [];
        $latCell = 0.00003;
        $lngCell = 0.00005;
        for ($i = 0; $i < $count; $i++) {
            $latBucket = (int) floor($points[$i]['lat'] / $latCell);
            $lngBucket = (int) floor($points[$i]['lng'] / $lngCell);
            $nearbyIndexes = [];
            for ($latOffset = -1; $latOffset <= 1; $latOffset++) {
                for ($lngOffset = -1; $lngOffset <= 1; $lngOffset++) {
                    $nearbyIndexes = array_merge($nearbyIndexes, $spatialBuckets[($latBucket + $latOffset).'|'.($lngBucket + $lngOffset)] ?? []);
                }
            }
            foreach ($nearbyIndexes as $j) {
                $iIsTerminal = in_array($points[$i]['kind'], ['sling', 'loop'], true);
                $jIsTerminal = in_array($points[$j]['kind'], ['sling', 'loop'], true);
                // A house one metre from the roadside trench is still a leaf, not the
                // trench junction itself. Merge only duplicate readings of the same
                // terminal; never merge a customer endpoint into a trench node.
                if ($iIsTerminal xor $jIsTerminal) {
                    continue;
                }
                // cheap pre-filter before the exact distance
                if (abs($points[$i]['lat'] - $points[$j]['lat']) > 0.00003) {
                    continue;
                }
                if (abs($points[$i]['lng'] - $points[$j]['lng']) > 0.00005) {
                    continue;
                }
                $mergeDistance = $iIsTerminal && $jIsTerminal ? 0.5 : self::NODE_MERGE_M;
                if ($this->geometry->distanceMeters(
                    $points[$i]['lat'], $points[$i]['lng'],
                    $points[$j]['lat'], $points[$j]['lng']
                ) <= $mergeDistance
                    && (! $iIsTerminal || ($points[$i]['zo_tag'] ?? null) === ($points[$j]['zo_tag'] ?? null))) {
                    $parent[$find($j)] = $find($i);
                }
            }
            $spatialBuckets[$latBucket.'|'.$lngBucket][] = $i;
        }

        $nodeOf = [];
        $nodes = [];
        // Both an explicit house ('sling') and a bare reserve loop ('loop') get their own
        // dedicated drop — see walkHouseDropChains() — so both act as checkpoints here.
        $dropCheckpointNodes = [];
        $nonTerminalNodes = [];
        $terminalPointIndexesByNode = [];
        for ($i = 0; $i < $count; $i++) {
            $root = $find($i);
            if (! isset($nodes[$root])) {
                $nodes[$root] = [$points[$root]['lat'], $points[$root]['lng']];
            }
            if (in_array($points[$i]['kind'], ['sling', 'loop'], true)) {
                $dropCheckpointNodes[$root] = true;
                $terminalPointIndexesByNode[$root][] = $i;
            } else {
                $nonTerminalNodes[$root] = true;
            }
            $nodeOf[$i] = $root;
        }

        // A house/reserve loop is an endpoint, never a junction between customers.
        // Select exactly one compatible surveyed branch node for every dedicated
        // terminal. The route may then share the main trench, but it cannot pass
        // through another house on its way to the cabinet.
        $terminalOnlyNodes = array_diff_key($dropCheckpointNodes, $nonTerminalNodes);
        $terminalPreferredLinks = [];
        foreach ($terminalPointIndexesByNode as $terminalNode => $terminalIndexes) {
            if (! isset($terminalOnlyNodes[$terminalNode])) {
                continue;
            }

            foreach ($terminalIndexes as $terminalIndex) {
                $terminalIdents = $this->pointDuctIdentities($points[$terminalIndex]);
                $candidates = [];

                foreach ([-1, 1] as $direction) {
                    for ($j = $terminalIndex + $direction; $j >= 0 && $j < $count; $j += $direction) {
                        if (in_array($points[$j]['kind'], ['sling', 'loop'], true)) {
                            continue;
                        }

                        $candidateIdents = $this->pointDuctIdentities($points[$j]);
                        $shared = $terminalIdents === []
                            ? $candidateIdents
                            : array_intersect_key($terminalIdents, $candidateIdents);
                        if ($shared === []) {
                            continue;
                        }

                        $distance = $this->geometry->distanceMeters(
                            $points[$terminalIndex]['lat'], $points[$terminalIndex]['lng'],
                            $points[$j]['lat'], $points[$j]['lng']
                        );
                        if ($nodeOf[$j] !== $terminalNode && $distance <= self::CUSTOMER_SPUR_TO_TRENCH_M) {
                            $candidates[] = [
                                'neighbor' => $nodeOf[$j],
                                'terminal_index' => $terminalIndex,
                                'neighbor_index' => $j,
                                'distance' => $distance,
                                'idents' => $shared,
                            ];
                        }

                        // The first matching non-terminal in each recording direction
                        // is the surveyed attachment for this branch.
                        break;
                    }
                }

                if ($candidates === []) {
                    continue;
                }
                usort($candidates, fn (array $left, array $right) => $left['distance'] <=> $right['distance']);
                $candidate = $candidates[0];
                if (! isset($terminalPreferredLinks[$terminalNode])
                    || $candidate['distance'] < $terminalPreferredLinks[$terminalNode]['distance']) {
                    $terminalPreferredLinks[$terminalNode] = $candidate;
                }
            }
        }

        $customerTerminalNodes = [];
        foreach ($terminalPointIndexesByNode as $terminalNode => $terminalIndexes) {
            if (! isset($terminalOnlyNodes[$terminalNode], $terminalPreferredLinks[$terminalNode])) {
                continue;
            }
            $isHouse = collect($terminalIndexes)->contains(
                fn (int $index) => $points[$index]['kind'] === 'sling'
            );
            $hasCustomerDuct = collect($terminalPreferredLinks[$terminalNode]['idents'])->contains(
                fn (array $identity) => $identity['type'] === '10/8'
            );
            if ($isHouse || $hasCustomerDuct) {
                $customerTerminalNodes[$terminalNode] = true;
            }
        }

        $edges = [];
        $mergeEdge = static function (int $a, int $b, array $idents) use (&$edges): void {
            if ($a === $b) {
                return;
            }

            $key = min($a, $b).'|'.max($a, $b);
            if (! isset($edges[$key])) {
                $edges[$key] = ['a' => $a, 'b' => $b, 'idents' => $idents];

                return;
            }

            foreach ($idents as $identityKey => $attrs) {
                $existing = $edges[$key]['idents'][$identityKey] ?? null;
                $edges[$key]['idents'][$identityKey] = $existing
                    ? ['count' => max($existing['count'], $attrs['count'])] + $existing
                    : $attrs;
            }
        };

        $returnBuckets = [];
        $returnLatCell = 10 / 111320;
        $returnLngCell = 10 / (111320 * cos(deg2rad($points[0]['lat'])));
        foreach ($points as $pointIndex => $point) {
            $key = ((int) floor($point['lat'] / $returnLatCell)).'|'.((int) floor($point['lng'] / $returnLngCell));
            $returnBuckets[$key][] = $pointIndex;
        }

        for ($i = 1; $i < $count; $i++) {
            $a = $nodeOf[$i - 1];
            $b = $nodeOf[$i];
            $fromPointIndex = $i - 1;
            if ($a === $b) {
                continue;
            }

            $gap = $this->geometry->distanceMeters(
                $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                $points[$i]['lat'], $points[$i]['lng']
            );

            // The surveyor often finishes one customer branch, walks back near an older
            // junction, and immediately starts the next branch without a pen-up marker.
            // That walk-back is not cable. Re-anchor the new point to the distinctly closer
            // earlier node instead of drawing a false diagonal from the previous endpoint.
            $returnNode = null;
            $returnPointIndex = null;
            $returnDistance = INF;
            $returnIdentityMatches = -1;
            $toIdents = $this->pointDuctIdentities($points[$i]);
            $followsTerminal = in_array($points[$i - 1]['kind'], ['sling', 'loop'], true);
            $returnSearchRadius = $followsTerminal ? self::CUSTOMER_SPUR_TO_TRENCH_M : 10.0;
            $returnLatBucket = (int) floor($points[$i]['lat'] / $returnLatCell);
            $returnLngBucket = (int) floor($points[$i]['lng'] / $returnLngCell);
            $returnCellRadius = (int) ceil($returnSearchRadius / 10);
            $returnCandidates = [];
            for ($latOffset = -$returnCellRadius; $latOffset <= $returnCellRadius; $latOffset++) {
                for ($lngOffset = -$returnCellRadius; $lngOffset <= $returnCellRadius; $lngOffset++) {
                    $returnCandidates = array_merge($returnCandidates, $returnBuckets[($returnLatBucket + $latOffset).'|'.($returnLngBucket + $lngOffset)] ?? []);
                }
            }
            foreach ($returnCandidates as $j) {
                if ($j >= $i - 1) {
                    continue;
                }
                if (in_array($points[$j]['kind'], ['sling', 'loop'], true)) {
                    continue;
                }
                $distance = $this->geometry->distanceMeters(
                    $points[$j]['lat'], $points[$j]['lng'],
                    $points[$i]['lat'], $points[$i]['lng']
                );
                if ($distance > $returnSearchRadius) {
                    continue;
                }
                $identityMatches = count(array_intersect_key(
                    $this->pointDuctIdentities($points[$j]),
                    $toIdents
                ));
                // At a bundle split, topology is stronger evidence than a metre or two
                // of geometric proximity: a 3-colour branch must return to the 3-colour
                // junction, not to a slightly closer 2-colour sibling branch.
                if ($identityMatches > $returnIdentityMatches
                    || ($identityMatches === $returnIdentityMatches && $distance < $returnDistance)) {
                    $returnDistance = $distance;
                    $returnNode = $nodeOf[$j];
                    $returnPointIndex = $j;
                    $returnIdentityMatches = $identityMatches;
                }
            }
            $shouldReanchor = $returnNode !== null
                && (($followsTerminal && $returnIdentityMatches > 0)
                    || ($returnDistance <= 10.0 && $returnDistance + 2.0 < $gap));
            if ($shouldReanchor) {
                $a = $returnNode;
                $fromPointIndex = $returnPointIndex;
                if ($a === $b) {
                    continue;
                }
                $gap = $returnDistance;
            }

            $terminalNode = isset($customerTerminalNodes[$a])
                ? $a
                : (isset($customerTerminalNodes[$b]) ? $b : null);
            $otherNode = $terminalNode === $a ? $b : $a;
            $isPreferredTerminalEdge = $terminalNode !== null
                && isset($terminalPreferredLinks[$terminalNode])
                && $terminalPreferredLinks[$terminalNode]['neighbor'] === $otherNode;
            if ($terminalNode !== null && ! $isPreferredTerminalEdge) {
                continue;
            }

            $fromIdents = $this->pointDuctIdentities($points[$fromPointIndex]);
            $shared = array_intersect_key($fromIdents, $toIdents);
            $hasSameTaggedCustomerDuct = collect($shared)->contains(
                fn (array $identity) => $identity['type'] === '10/8' && $identity['tag'] !== null
            );
            $hasTransitDuct = collect($shared)->contains(
                fn (array $identity) => (bool) ($identity['transit'] ?? false)
            );
            $touchesCustomerTerminal = in_array($points[$fromPointIndex]['kind'], ['sling', 'loop'], true)
                || in_array($points[$i]['kind'], ['sling', 'loop'], true);
            $allowedGap = $isPreferredTerminalEdge
                ? self::CUSTOMER_SPUR_TO_TRENCH_M
                : (($followsTerminal && $returnNode !== null)
                    ? self::CUSTOMER_SPUR_TO_TRENCH_M
                    : (($hasSameTaggedCustomerDuct || $hasTransitDuct) && ! $touchesCustomerTerminal
                    ? self::TAGGED_DUCT_GAP_M
                    : self::TRENCH_GAP_M));
            if ($gap > $allowedGap) {
                continue;
            }

            if (count($shared) > 0) {
                $idents = $shared;
            } elseif (count($fromIdents) === 0) {
                $idents = $toIdents;
            } elseif (count($toIdents) === 0) {
                $idents = $fromIdents;
            } else {
                $idents = [];
            }

            $mergeEdge($a, $b, $idents);
        }

        // A duplicate terminal measurement can sit between the terminal and its
        // attachment in source order. Add the selected leaf edge explicitly so
        // recording order never turns one customer into the path to another.
        foreach ($terminalPreferredLinks as $terminalNode => $link) {
            $mergeEdge($terminalNode, $link['neighbor'], $link['idents']);
        }

        return [$nodes, array_values($edges), $dropCheckpointNodes];
    }

    /**
     * Duct identities present at a single survey point.
     *
     * @return array<string, array{count:int,type:string,color:?string,tag:?string}>
     */
    private function pointDuctIdentities(array $point): array
    {
        if (! empty($point['duct_identities'])) {
            $idents = [];
            foreach ($point['duct_identities'] as $identity) {
                $colors = $identity['type'] === '14/10' ? $identity['colors'] : [];
                if (count($colors) > 0) {
                    foreach ($colors as $color) {
                        $key = $identity['type'].'|'.$color.($identity['tag'] !== null ? '|zo:'.$identity['tag'] : '');
                        $idents[$key] = [
                            'count' => $identity['count'],
                            'type' => $identity['type'],
                            'color' => $color,
                            'tag' => $identity['tag'],
                            'transit' => (bool) ($identity['transit'] ?? false),
                        ];
                    }

                    continue;
                }

                $tag = $identity['tag'];
                $key = $identity['type'].'|'.($tag !== null ? 'zo:'.$tag : 'anon');
                $idents[$key] = [
                    'count' => $identity['count'],
                    'type' => $identity['type'],
                    'color' => null,
                    'tag' => $tag,
                    'transit' => (bool) ($identity['transit'] ?? false),
                ];
            }

            return $idents;
        }

        if (! $point['microduct_type']) {
            return []; // "Rov", "Rov +MD" — trench without duct info
        }

        $idents = [];
        if ($point['microduct_type'] === '14/10' && count($point['colors'] ?? []) > 0) {
            foreach ($point['colors'] as $color) {
                $count = max(1, (int) ($point['color_counts'][$color] ?? 1));
                $key = '14/10|'.$color.($point['zo_tag'] !== null ? '|zo:'.$point['zo_tag'] : '');
                $idents[$key] = [
                    'count' => $count,
                    'type' => '14/10',
                    'color' => $color,
                    'tag' => $point['zo_tag'],
                    'transit' => (bool) ($point['transit'] ?? false),
                ];
            }
        } else {
            $tag = $point['zo_tag'];
            $key = $point['microduct_type'].'|'.($tag !== null ? 'zo:'.$tag : 'anon');
            $idents[$key] = [
                'count' => $point['microduct_count'],
                'type' => $point['microduct_type'],
                'color' => $point['colors'][0] ?? null,
                'transit' => (bool) ($point['transit'] ?? false),
                'tag' => $tag,
            ];
        }

        return $idents;
    }

    private function inferImplicitCabinetTags(array $ducts): array
    {
        foreach ($ducts as $index => $duct) {
            if ($duct['zo_tag'] !== null || ($duct['transit'] ?? false) === true || count($duct['path']) < 2) {
                continue;
            }

            $tags = [];
            foreach ($ducts as $candidateIndex => $candidate) {
                if ($candidateIndex === $index || $candidate['zo_tag'] === null) {
                    continue;
                }
                foreach ($this->pathEndpoints($duct['path']) as $endpoint) {
                    if ($this->geometry->distanceToRoute($endpoint[0], $endpoint[1], $candidate['path']) <= self::NODE_MERGE_M) {
                        $tags[$candidate['zo_tag']] = true;
                    }
                }
            }

            if (count($tags) !== 1) {
                continue;
            }

            $tag = (string) array_key_first($tags);
            $ducts[$index]['zo_tag'] = $tag;
            $ducts[$index]['key'] = $duct['microduct_type'].'|zo:'.$tag;
            $ducts[$index]['label'] = $this->ductLabel([
                'type' => $duct['microduct_type'],
                'color' => $duct['color'],
                'tag' => $tag,
            ], $duct['microduct_count']);
        }

        return $ducts;
    }

    private function ductLabel(array $attrs, int $count): string
    {
        $countLabel = $count > 1 ? $count.'x' : '';
        $suffix = $attrs['color'] ?? ($attrs['tag'] !== null ? 'ZO '.$attrs['tag'] : '');

        return trim('MC '.$countLabel.$attrs['type'].' '.$suffix);
    }

    /**
     * Split an edge set into chains. Chains are cut at junction nodes
     * (degree ≠ 2), and — when $groupOf is given — wherever two adjacent
     * edges belong to different groups (e.g. the duct count changes).
     *
     * @param  array  $edgeList  each ['a' => nodeId, 'b' => nodeId, ...]
     * @param  callable|null  $groupOf  fn(edge): scalar
     * @return array<int, array{nodes: int[], edges: int[]}>
     */
    private function walkChains(array $edgeList, array $nodes, ?callable $groupOf, array $forcedCutNodes = []): array
    {
        $adjacency = [];
        foreach ($edgeList as $index => $edge) {
            $adjacency[$edge['a']][] = $index;
            $adjacency[$edge['b']][] = $index;
        }

        $isCut = function (int $node) use ($adjacency, $edgeList, $groupOf, $forcedCutNodes): bool {
            if (isset($forcedCutNodes[$node])) {
                return true;
            }
            $incident = $adjacency[$node] ?? [];
            if (count($incident) !== 2) {
                return true;
            }

            return $groupOf !== null
                && $groupOf($edgeList[$incident[0]]) !== $groupOf($edgeList[$incident[1]]);
        };

        $visited = [];
        $chains = [];

        $walk = function (int $startNode, int $firstEdge) use (&$visited, $adjacency, $edgeList, $isCut, &$chains): void {
            $chainNodes = [$startNode];
            $chainEdges = [];
            $current = $startNode;
            $edge = $firstEdge;

            while (true) {
                $visited[$edge] = true;
                $chainEdges[] = $edge;
                $current = $edgeList[$edge]['a'] === $current ? $edgeList[$edge]['b'] : $edgeList[$edge]['a'];
                $chainNodes[] = $current;

                if ($isCut($current)) {
                    break;
                }
                $next = null;
                foreach ($adjacency[$current] as $candidate) {
                    if (empty($visited[$candidate])) {
                        $next = $candidate;
                        break;
                    }
                }
                if ($next === null) {
                    break;
                }
                $edge = $next;
            }

            $chains[] = ['nodes' => $chainNodes, 'edges' => $chainEdges];
        };

        foreach (array_keys($adjacency) as $node) {
            if (! $isCut($node)) {
                continue;
            }
            foreach ($adjacency[$node] as $edgeIndex) {
                if (empty($visited[$edgeIndex])) {
                    $walk($node, $edgeIndex);
                }
            }
        }
        // leftovers are pure loops — start anywhere
        foreach ($edgeList as $edgeIndex => $edge) {
            if (empty($visited[$edgeIndex])) {
                $walk($edge['a'], $edgeIndex);
            }
        }

        return $chains;
    }

    /**
     * Walk a customer-drop (10/8) identity's subgraph, emitting one path PER checkpoint
     * (house or reserve loop) it reaches — each path runs from the walk's true origin (a
     * junction, i.e. the shared trunk/cabinet end) up to and including that checkpoint,
     * reusing whatever an earlier one's path already covered. A survey often keeps walking
     * straight past house/loop A to reach house/loop B; without this, B's drop would start
     * at A instead of running independently back to the trunk. Still hard-cuts on
     * structural junctions and on $groupOf changes (count changes), same as walkChains —
     * the two just diverge on whether reaching a checkpoint stops the walk (walkChains) or
     * only checkpoints it.
     *
     * @param  array  $edgeList  each ['a' => nodeId, 'b' => nodeId, ...]
     * @param  array<int,bool>  $checkpointNodes  node ids that are a house or reserve loop
     * @param  callable|null  $groupOf  fn(edge): scalar
     * @return array<int, array{nodes: int[], group: mixed}>
     */
    private function walkHouseDropChains(array $edgeList, array $checkpointNodes, ?callable $groupOf): array
    {
        $adjacency = [];
        foreach ($edgeList as $index => $edge) {
            $adjacency[$edge['a']][] = $index;
            $adjacency[$edge['b']][] = $index;
        }

        $isHardCut = function (int $node) use ($adjacency, $edgeList, $groupOf): bool {
            $incident = $adjacency[$node] ?? [];
            if (count($incident) !== 2) {
                return true;
            }

            return $groupOf !== null
                && $groupOf($edgeList[$incident[0]]) !== $groupOf($edgeList[$incident[1]]);
        };

        $visited = [];
        $chains = [];

        $walk = function (int $startNode, int $firstEdge) use (&$visited, $adjacency, $edgeList, $isHardCut, $checkpointNodes, $groupOf, &$chains): void {
            $chainNodes = [$startNode];
            $current = $startNode;
            $edge = $firstEdge;
            $group = $groupOf !== null ? $groupOf($edgeList[$edge]) : null;
            $reachedCheckpoint = false;

            while (true) {
                $visited[$edge] = true;
                $current = $edgeList[$edge]['a'] === $current ? $edgeList[$edge]['b'] : $edgeList[$edge]['a'];
                $chainNodes[] = $current;

                if (isset($checkpointNodes[$current])) {
                    $chains[] = ['nodes' => $chainNodes, 'group' => $group];
                    $reachedCheckpoint = true;
                }

                if ($isHardCut($current)) {
                    break;
                }
                $next = null;
                foreach ($adjacency[$current] as $candidate) {
                    if (empty($visited[$candidate])) {
                        $next = $candidate;
                        break;
                    }
                }
                if ($next === null) {
                    break;
                }
                $edge = $next;
            }

            // No house/loop anywhere on this walk (not yet surveyed to a customer, or a
            // plain ZO-tagged run) — keep it as one duct, same as walkChains would.
            if (! $reachedCheckpoint) {
                $chains[] = ['nodes' => $chainNodes, 'group' => $group];
            }
        };

        foreach (array_keys($adjacency) as $node) {
            if (! $isHardCut($node)) {
                continue;
            }
            foreach ($adjacency[$node] as $edgeIndex) {
                if (empty($visited[$edgeIndex])) {
                    $walk($node, $edgeIndex);
                }
            }
        }
        foreach ($edgeList as $edgeIndex => $edge) {
            if (empty($visited[$edgeIndex])) {
                $walk($edge['a'], $edgeIndex);
            }
        }

        return $chains;
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
                    'name' => $this->cabinetLabel($p['code']),
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
            $this->projectRoutingTrenchPaths($project->id, $batch)
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

        $duplicatePointNumbers = collect($points)->countBy('point_no')->filter(fn (int $count) => $count > 1)->keys()->values();
        $unrecognizedCodes = collect($points)->where('kind', 'other')->pluck('code')->filter()->unique()->values();
        $customerPointsWithoutCabinet = collect($points)->filter(fn (array $point) => ($point['microduct_type'] ?? null) === '10/8'
            && in_array($point['kind'], ['trench', 'sling'], true)
            && blank($point['zo_tag'] ?? null)
        )->pluck('point_no')->values();
        $terminalDucts = collect($network['ducts'])->filter(fn (array $duct) => ($duct['prepared_sling'] ?? false) || isset($duct['_terminal_point'])
        );
        $unreachableDucts = $terminalDucts->reject(fn (array $duct) => (bool) ($duct['cabinet_reached'] ?? false));
        $qualityErrors = collect()
            ->when($duplicatePointNumbers->isNotEmpty(), fn (Collection $errors) => $errors->push('Dupli brojevi tačaka: '.$duplicatePointNumbers->take(12)->join(', ').'.'))
            ->when($unrecognizedCodes->isNotEmpty(), fn (Collection $errors) => $errors->push($unrecognizedCodes->count().' opisa nije prepoznato.'))
            ->when($customerPointsWithoutCabinet->isNotEmpty(), fn (Collection $errors) => $errors->push($customerPointsWithoutCabinet->count().' korisničkih 10/8 tačaka nema -ZO oznaku.'))
            ->when($unreachableDucts->isNotEmpty(), fn (Collection $errors) => $errors->push($unreachableDucts->count().' korisničkih linija nema dokazanu putanju kroz rov do ODO-a.'))
            ->values();

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
                $binding = $this->resolveDuctBinding($duct, $cabinets, $odfs, $houseCandidates);
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
            'odfs' => $this->mergeOdfPoints($points),
            'manholes' => collect($points)->where('kind', 'manhole')->count(),
            'houses' => collect($points)->where('kind', 'sling')->count(),
            'prepared_slings' => collect($points)->where('kind', 'sling')->where('prepared_sling', true)->count(),
            'unrecognized_codes' => $unrecognizedCodes->all(),
            'quality' => [
                'status' => $qualityErrors->isEmpty() ? 'ready' : 'blocked',
                'errors' => $qualityErrors->all(),
                'complete_drop_routes' => $terminalDucts->count() - $unreachableDucts->count(),
                'unreachable_drop_routes' => $unreachableDucts->count(),
                'duplicate_point_numbers' => $duplicatePointNumbers->all(),
                'customer_points_without_cabinet' => $customerPointsWithoutCabinet->all(),
                'issue_points' => collect($points)->filter(fn (array $point) => $point['kind'] === 'other'
                    || $duplicatePointNumbers->contains($point['point_no'])
                    || $customerPointsWithoutCabinet->contains($point['point_no'])
                )->map(fn (array $point) => [
                    'point_no' => $point['point_no'], 'code' => $point['code'],
                    'lat' => $point['lat'], 'lng' => $point['lng'],
                ])->values()->all(),
            ],
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
            foreach ($points as $point) {
                SurveyPoint::create([
                    'project_id' => $project->id,
                    'import_batch' => $batch,
                    'source_file' => $filename ?: null,
                    'point_no' => $point['point_no'],
                    'x' => $point['x'],
                    'y' => $point['y'],
                    'z' => $point['z'],
                    'latitude' => $point['lat'],
                    'longitude' => $point['lng'],
                    'code' => $point['code'],
                    'kind' => $point['kind'],
                ]);
                $created['points']++;
            }

            // 1. Cabinets and ODFs first, so ducts can bind to them.
            foreach (collect($points)->where('kind', 'cabinet') as $point) {
                $nearbyCabinet = $this->nearestWithin(
                    Cabinet::where('project_id', $project->id)->whereNotNull('latitude')->get(),
                    [$point['lat'], $point['lng']],
                    self::EXISTING_ELEMENT_TOLERANCE_M
                );
                if ($nearbyCabinet !== null) {
                    // A previously drafted cabinet may still have a generated name such
                    // as "FTTH 1055". The surveyed TXT code is authoritative for this
                    // physical cabinet and must be what the map displays.
                    $nearbyCabinet->update(['name' => $this->cabinetLabel($point['code'])]);

                    continue;
                }
                Cabinet::create([
                    'project_id' => $project->id,
                    'name' => $this->uniqueName(Cabinet::class, $project->id, $this->cabinetLabel($point['code'])),
                    'address' => 'Geodetski snimak',
                    'splitter_count' => 3,
                    'ports_per_splitter' => 4,
                    'latitude' => $point['lat'],
                    'longitude' => $point['lng'],
                    'import_batch' => $batch,
                ]);
                $created['cabinets']++;
            }
            $allCabinets = Cabinet::where('project_id', $project->id)->whereNotNull('latitude')->get();

            foreach ($this->mergeOdfPoints($points) as $odfPoint) {
                if ($this->existsNearby(Odf::class, $project->id, $odfPoint['lat'], $odfPoint['lng'])) {
                    continue;
                }
                Odf::create([
                    'project_id' => $project->id,
                    'name' => $this->uniqueName(Odf::class, $project->id, 'ODF'),
                    'address' => 'Geodetski snimak',
                    'fiber_capacity' => 144,
                    'port_count' => 48,
                    'latitude' => $odfPoint['lat'],
                    'longitude' => $odfPoint['lng'],
                    'import_batch' => $batch,
                ]);
                $created['odfs']++;
            }
            $allOdfs = Odf::where('project_id', $project->id)->whereNotNull('latitude')->get();

            // 2. Slings (points that explicitly name a house) mark a prepared house
            //    connection — create unassigned houses BEFORE ducts, so a duct ending at
            //    one can be bound and typed as a 'drop'. A bare reserve loop ('loop' kind,
            //    no house named) does NOT create a house — it's just a coiled cable reserve.
            $slingPoints = collect($points)->where('kind', 'sling');
            foreach ($slingPoints as $point) {
                if (($point['prepared_sling'] ?? false)
                    && filled($point['house_ref'] ?? null)
                    && ! ($point['house_ref_generated'] ?? false)) {
                    $reference = (string) $point['house_ref'];
                    $exists = House::where('project_id', $project->id)
                        ->where(fn ($query) => $query->where('label', $reference)->orWhere('address', 'like', '%'.$reference.'%'))
                        ->exists();
                    if (! $exists) {
                        House::create([
                            'project_id' => $project->id,
                            'label' => $this->uniqueHouseLabel($project->id, $reference),
                            'address' => 'Planirana kuca iz SLINGA '.$reference,
                            'status' => 'planned',
                            'latitude' => null,
                            'longitude' => null,
                            'import_batch' => $batch,
                        ]);
                        $created['houses']++;
                    }

                    continue;
                }
                if ($this->existsNearby(House::class, $project->id, $point['lat'], $point['lng'])) {
                    continue;
                }
                House::create([
                    'project_id' => $project->id,
                    'label' => $this->uniqueHouseLabel(
                        $project->id,
                        (($point['prepared_sling'] ?? false) ? 'Slinga t' : 'Kuca t').$point['point_no']
                    ),
                    'address' => $point['code'] ?: 'Geodetski snimak',
                    'status' => 'planned',
                    'latitude' => $point['lat'],
                    'longitude' => $point['lng'],
                    'import_batch' => $batch,
                ]);
                $created['houses']++;
            }
            $allHouses = House::where('project_id', $project->id)->get();

            $network = $this->buildNetwork(
                $points,
                $allCabinets->map(fn (Cabinet $cabinet) => [
                    'kind' => 'cabinet',
                    'code' => $cabinet->name,
                    'lat' => (float) $cabinet->latitude,
                    'lng' => (float) $cabinet->longitude,
                ])->values()->all(),
                $this->projectRoutingTrenchPaths($project->id, $batch)
            );

            // buildNetwork() already resolved this file's own topology (e.g. which branches
            // are genuinely separate at a junction) — findExisting*() must never second-guess
            // that by merging two routes THIS SAME call just created into each other just
            // because they happen to touch at a shared point. It only exists to continue a
            // route from an EARLIER, separate import, so freshly-created ids are excluded.
            $freshRouteIds = [];
            $freshDropHouseIds = [];

            // 3. Physical trenches.
            foreach ($network['trenches'] as $index => $chain) {
                $existing = $this->findExistingRouteGeometry($project->id, 'trench', $chain['path'], $freshRouteIds);
                if ($existing) {
                    $mergedPath = $this->mergeTouchingPaths($existing->path ?? [], $chain['path']);
                    $existing->update([
                        'path' => $mergedPath,
                        'duct_length_m' => $this->geometry->polylineLength($mergedPath),
                        'note' => $this->appendImportNote($existing->note, 'Geodetski snimak: '.$chain['code']),
                    ]);

                    continue;
                }

                $trench = NetworkRoute::create([
                    'project_id' => $project->id,
                    'name' => $this->uniqueName(NetworkRoute::class, $project->id, 'Rov '.($index + 1)),
                    'route_type' => 'trench',
                    'installation_type' => 'underground',
                    'counts_as_trench' => true,
                    'duct_length_m' => $chain['length_m'],
                    'fiber_length_m' => 0,
                    'fiber_count' => 0,
                    'microduct_count' => 0,
                    'microduct_type' => null,
                    'status' => 'planned',
                    'path' => $chain['path'],
                    'note' => 'Geodetski snimak: '.$chain['code'],
                    'import_batch' => $batch,
                ]);
                $freshRouteIds[] = $trench->id;
                $created['trenches']++;
            }

            // 4. Microducts as routes, bound to their cabinet/ODF/house and typed by topology.
            foreach ($network['ducts'] as $duct) {
                $binding = $this->resolveDuctBinding($duct, $allCabinets, $allOdfs, $allHouses, $cabinetOverrides);
                $cabinet = $binding['cabinet'];
                $odf = $binding['odf'];
                $house = $binding['house'];
                $routeType = $binding['route_type'];

                // Several approximate terminal readings can resolve to the same physical
                // house. They describe one customer connection, not parallel 10/8 drops.
                if ($routeType === 'drop' && $house !== null && isset($freshDropHouseIds[$house->id])) {
                    continue;
                }

                if ((($duct['prepared_sling'] ?? false) || isset($duct['_terminal_point'])) && $duct['zo_tag'] !== null) {
                    $cabinetReached = (bool) ($duct['cabinet_reached'] ?? false)
                        || ($cabinet !== null && min(...array_map(
                            fn (array $endpoint) => $this->geometry->distanceMeters(
                                (float) $cabinet->latitude, (float) $cabinet->longitude,
                                $endpoint[0], $endpoint[1]
                            ),
                            $this->pathEndpoints($duct['path'])
                        )) <= self::DUCT_ENDPOINT_BIND_M);
                    if (! $cabinetReached) {
                        throw new InvalidArgumentException('Korisnicka linija '.($duct['house_ref'] ?? $duct['label']).' nema dokazani povezani put do ZO '.$duct['zo_tag'].'. Uvoz je zaustavljen.');
                    }
                }

                $existing = $this->findExistingDuctRoute($project->id, $duct, $routeType, $house?->id, $freshRouteIds);
                if ($existing) {
                    $mergedPath = $this->mergeTouchingPaths($existing->path ?? [], $duct['path']);
                    $existing->update([
                        'path' => $mergedPath,
                        'duct_length_m' => $this->geometry->polylineLength($mergedPath),
                        'microduct_count' => max((int) $existing->microduct_count, (int) $duct['microduct_count']),
                        'note' => $this->appendImportNote($existing->note, $this->ductImportNote($duct)),
                    ]);
                    if ($routeType === 'drop' && $house !== null) {
                        $freshDropHouseIds[$house->id] = true;
                    }

                    continue;
                }

                // A drop always runs cabinet(ODO) -> house; feeder/distribution keep the
                // existing odf -> cabinet wiring, only route_type changes between them.
                [$fromType, $fromId, $toType, $toId] = $routeType === 'drop'
                    ? ['cabinet', $cabinet?->id, 'house', $house?->id]
                    : [$odf ? 'odf' : null, $odf?->id, $cabinet ? 'cabinet' : null, $cabinet?->id];

                $route = NetworkRoute::create([
                    'project_id' => $project->id,
                    'odf_id' => $odf?->id,
                    'cabinet_id' => $cabinet?->id,
                    'from_type' => $fromType,
                    'from_id' => $fromId,
                    'to_type' => $toType,
                    'to_id' => $toId,
                    'name' => $this->uniqueName(NetworkRoute::class, $project->id, $duct['label']),
                    'route_type' => $routeType,
                    'installation_type' => 'underground',
                    'counts_as_trench' => false,
                    'duct_length_m' => $duct['length_m'],
                    'fiber_length_m' => 0,
                    'fiber_count' => 0, // mikrocijev bez uvucenog kabla
                    'microduct_count' => $duct['microduct_count'],
                    'microduct_type' => $duct['microduct_type'],
                    'status' => 'planned',
                    'path' => $duct['path'],
                    'note' => $this->ductImportNote($duct),
                    'import_batch' => $batch,
                ]);
                $freshRouteIds[] = $route->id;
                if ($routeType === 'drop' && $house !== null) {
                    $freshDropHouseIds[$house->id] = true;
                }
                $this->branchSync->createBranchForRoute($route);
                $created['ducts']++;
            }

            // 5. Appendix-item point kinds: manholes, borings (FI 130), splices, reserve loops.
            $created['manholes'] = $this->createAppendixPointsFromSurvey($project->id, $points, 'manhole', 'manhole', $batch);
            $created['borings'] = $this->createAppendixPointsFromSurvey($project->id, $points, 'boring', 'boring_fi_130', $batch);
            $created['splices'] = $this->createAppendixPointsFromSurvey($project->id, $points, 'splice', 'splice', $batch);
            $created['loops'] = $this->createAppendixPointsFromSurvey($project->id, $points, 'loop', 'loop', $batch);
        });

        return $created;
    }

    /**
     * Existing route geometry is preferred. If routes were removed but their imported
     * survey points remain, rebuild the old main-trench walks from those coordinates.
     */
    private function projectRoutingTrenchPaths(int $projectId, string $excludeBatch): array
    {
        $savedPaths = NetworkRoute::where('project_id', $projectId)
            // Existing backbone/distribution geometry is the mapped main corridor the
            // customer microduct must follow after its private spur reaches the road.
            // Never include drops here: that would make one house a shortcut to another.
            ->whereIn('route_type', ['trench', 'backbone', 'feeder', 'distribution'])
            ->where(fn ($query) => $query->whereNull('import_batch')->orWhere('import_batch', '!=', $excludeBatch))
            ->get()
            ->pluck('path')
            ->filter(fn ($path) => is_array($path) && count($path) >= 2)
            ->values()
            ->all();
        if ($savedPaths !== []) {
            return $savedPaths;
        }

        $paths = [];
        $batches = SurveyPoint::where('project_id', $projectId)
            ->where('kind', 'trench')
            ->where('import_batch', '!=', $excludeBatch)
            ->orderBy('import_batch')
            ->orderBy('point_no')
            ->get()
            ->groupBy('import_batch');
        foreach ($batches as $batchPoints) {
            $current = [];
            foreach ($batchPoints as $point) {
                $coordinate = [(float) $point->latitude, (float) $point->longitude];
                if ($current !== [] && $this->geometry->distanceBetweenPoints(end($current), $coordinate) > self::TRENCH_GAP_M) {
                    if (count($current) >= 2) {
                        $paths[] = $this->geometry->compactPath($current);
                    }
                    $current = [];
                }
                $current[] = $coordinate;
            }
            if (count($current) >= 2) {
                $paths[] = $this->geometry->compactPath($current);
            }
        }

        return $paths;
    }

    /**
     * @param  int[]  $excludeIds  routes created earlier in THIS SAME confirm() call — never
     *                             a match candidate, see the comment where $freshRouteIds is built
     */
    private function findExistingDuctRoute(int $projectId, array $duct, string $routeType, ?int $houseId, array $excludeIds): ?NetworkRoute
    {
        $query = NetworkRoute::where('project_id', $projectId)
            ->where('route_type', $routeType)
            ->where('microduct_type', $duct['microduct_type'])
            ->whereNotIn('id', $excludeIds);

        if ($routeType === 'drop' && $houseId !== null) {
            // Neighbouring houses on the same trunk now get independent, overlapping
            // full-length paths (see walkHouseDropChains) — match strictly by destination
            // house rather than geometry, so house B's drop is never merged into house A's
            // just because it shares that prefix.
            return $query->where('to_type', 'house')->where('to_id', $houseId)->first();
        }

        if ($duct['zo_tag'] !== null) {
            $query->where('note', 'like', '%ZO '.$duct['zo_tag'].'%');
        } elseif ($duct['color'] !== null) {
            $query->where('name', 'like', '%'.$duct['color'].'%');
        } else {
            $query->where('name', 'like', '%'.$duct['microduct_type'].'%');
        }

        foreach ($query->get() as $route) {
            if ($this->pathsTouchOrOverlap($route->path ?? [], $duct['path'])) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @param  int[]  $excludeIds  routes created earlier in THIS SAME confirm() call
     */
    private function findExistingRouteGeometry(int $projectId, string $type, array $path, array $excludeIds): ?NetworkRoute
    {
        foreach (NetworkRoute::where('project_id', $projectId)->where('route_type', $type)->whereNotIn('id', $excludeIds)->get() as $route) {
            if ($this->pathsTouchOrOverlap($route->path ?? [], $path)) {
                return $route;
            }
        }

        return null;
    }

    private function pathsTouchOrOverlap(array $existing, array $incoming): bool
    {
        if (count($existing) < 2 || count($incoming) < 2) {
            return false;
        }

        $start = $incoming[0];
        $end = $incoming[count($incoming) - 1];
        $startNear = $this->geometry->distanceToRoute($start[0], $start[1], $existing) <= self::EXISTING_ELEMENT_TOLERANCE_M;
        $endNear = $this->geometry->distanceToRoute($end[0], $end[1], $existing) <= self::EXISTING_ELEMENT_TOLERANCE_M;
        if ($startNear && $endNear) {
            return true;
        }

        foreach ($this->pathEndpoints($existing) as $a) {
            foreach ($this->pathEndpoints($incoming) as $b) {
                if ($this->geometry->distanceBetweenPoints($a, $b) <= self::EXISTING_ELEMENT_TOLERANCE_M) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mergeTouchingPaths(array $existing, array $incoming): array
    {
        if (count($existing) < 2) {
            return $this->geometry->compactPath($incoming);
        }
        if (count($incoming) < 2) {
            return $this->geometry->compactPath($existing);
        }

        $variants = [
            [$existing, $incoming],
            [$existing, array_reverse($incoming)],
            [array_reverse($existing), $incoming],
            [array_reverse($existing), array_reverse($incoming)],
        ];

        foreach ($variants as [$a, $b]) {
            if ($this->geometry->distanceBetweenPoints($a[count($a) - 1], $b[0]) <= self::EXISTING_ELEMENT_TOLERANCE_M) {
                if ($this->geometry->distanceBetweenPoints($a[count($a) - 1], $b[0]) <= 0.5) {
                    array_shift($b);
                }

                return $this->geometry->compactPath(array_merge($a, $b));
            }
        }

        return $this->geometry->compactPath($existing);
    }

    private function pathEndpoints(array $path): array
    {
        return [$path[0], $path[count($path) - 1]];
    }

    private function ductImportNote(array $duct): string
    {
        return 'Geodetski snimak - mikrocijev'
            .($duct['color'] ? ' boja: '.$duct['color'] : '')
            .($duct['zo_tag'] !== null ? ' pripada: ZO '.$duct['zo_tag'] : '')
            .(($duct['prepared_sling'] ?? false) ? ' | SLINGA za kucu '.($duct['house_ref'] ?? '') : '');
    }

    private function appendImportNote(?string $existing, string $new): string
    {
        $existing = trim((string) $existing);
        if ($existing === '') {
            return $new;
        }
        if (str_contains($existing, $new)) {
            return $existing;
        }

        return $existing."\n".$new;
    }

    // -------------------------------------------------------------------------
    // Element binding helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve which cabinet/ODF/house a duct binds to, and the route_type that follows from
     * that binding. Shared by preview() (read-only display) and confirm() (persists), so the
     * two never disagree about what an import will do.
     *
     * @param  Collection  $cabinets  Cabinet models, or cabinet-shaped stand-ins with id=null
     * @param  Collection  $odfs
     * @param  Collection  $houses  House models, or house-shaped stand-ins with id=null
     * @param  array<string,int>  $cabinetOverrides  duct key => cabinet id, from a reviewed preview
     * @return array{cabinet:?object,odf:?Odf,house:?object,route_type:string,match_confidence:string,candidates:array}
     */
    private function resolveDuctBinding(array $duct, $cabinets, $odfs, $houses, array $cabinetOverrides = []): array
    {
        $house = null;
        if (filled($duct['house_ref'] ?? null)) {
            $reference = mb_strtolower((string) $duct['house_ref']);
            $house = $houses->first(fn ($candidate) => mb_strtolower((string) ($candidate->label ?? '')) === $reference
                || str_contains(mb_strtolower((string) ($candidate->address ?? '')), $reference));
        }
        $locatedHouses = $houses->filter(fn ($candidate) => $candidate->latitude !== null && $candidate->longitude !== null);
        if (($duct['prepared_sling'] ?? false) || isset($duct['_terminal_point']) || filled($duct['house_ref'] ?? null)) {
            $house ??= $this->nearestWithin($locatedHouses, end($duct['path']), self::EXISTING_ELEMENT_TOLERANCE_M)
                ?? $this->nearestWithin($locatedHouses, $duct['path'][0], self::EXISTING_ELEMENT_TOLERANCE_M);
        }
        $odf = $this->nearestWithin($odfs, $duct['path'][0], self::DUCT_ENDPOINT_BIND_M);

        $cabinet = null;
        $matchConfidence = 'none';
        $candidates = [];

        if (($duct['transit'] ?? false) === true) {
            // A field description explicitly marked as TRANZIT passes cabinets without
            // terminating in any of them. It must remain intentionally unassigned.
        } elseif (isset($cabinetOverrides[$duct['key']])) {
            $cabinet = $cabinets->firstWhere('id', (int) $cabinetOverrides[$duct['key']]);
            $matchConfidence = $cabinet ? 'manual' : 'none';
        } elseif ($duct['zo_tag'] !== null) {
            // Search real cabinets first — an existing, already-named cabinet is a firmer
            // match than a same-batch stand-in that merely happens to share a ZO tag.
            $cabinet = $cabinets->first(fn ($c) => $c->id !== null && $this->cabinetTag($c->name) === $duct['zo_tag'])
                ?? $cabinets->first(fn ($c) => $this->cabinetTag($c->name) === $duct['zo_tag']);
            $matchConfidence = $cabinet ? 'exact' : 'none';
        }

        if ($cabinet === null && ($duct['transit'] ?? false) !== true) {
            $start = $duct['path'][0];
            $end = end($duct['path']);
            $nearby = $cabinets
                ->map(fn ($c) => ['cabinet' => $c, 'distance_m' => min(
                    $this->geometry->distanceMeters((float) $c->latitude, (float) $c->longitude, $end[0], $end[1]),
                    $this->geometry->distanceMeters((float) $c->latitude, (float) $c->longitude, $start[0], $start[1]),
                )])
                ->filter(fn (array $row) => $row['distance_m'] <= self::DUCT_ENDPOINT_BIND_M)
                ->sortBy('distance_m')
                ->values();

            if ($nearby->isNotEmpty()) {
                $cabinet = $nearby->first()['cabinet'];
                // One nearby cabinet is deterministic (not a choice the user must review).
                // Keep "ambiguous" only when two or more cabinets can plausibly own it.
                $matchConfidence = $nearby->count() === 1 ? 'exact' : 'ambiguous';
                // Only cabinets that already have a real id can be picked from the review
                // UI — a same-batch stand-in has no id to send back as an override yet.
                $candidates = $nearby->filter(fn (array $row) => $row['cabinet']->id !== null)
                    ->map(fn (array $row) => [
                        'id' => $row['cabinet']->id,
                        'name' => $row['cabinet']->name,
                        'distance_m' => round($row['distance_m'], 1),
                    ])->values()->all();
            }
        }

        $routeType = match (true) {
            $house !== null => 'drop',
            $odf !== null => 'feeder',
            default => 'distribution',
        };

        return [
            'cabinet' => $cabinet,
            'odf' => $odf,
            'house' => $house,
            'route_type' => $routeType,
            'match_confidence' => $matchConfidence,
            'candidates' => $candidates,
        ];
    }

    /**
     * Persist a classified survey `kind` (manhole/boring/splice) as ProjectAppendixItem rows,
     * skipping any within EXISTING_ELEMENT_TOLERANCE_M of an already-stored item of the same type.
     * A survey point only records a location, never a measured length — boring length_m stays
     * null (quantity 0) so it doesn't corrupt the report's length-based BOM total.
     */
    private function createAppendixPointsFromSurvey(int $projectId, array $points, string $kind, string $appendixType, string $batch): int
    {
        $existing = ProjectAppendixItem::where('project_id', $projectId)->where('type', $appendixType)->get(['latitude', 'longitude']);
        $created = 0;

        foreach (collect($points)->where('kind', $kind) as $point) {
            $nearby = $existing->contains(fn ($item) => $item->latitude !== null && $this->geometry->distanceMeters(
                (float) $item->latitude, (float) $item->longitude, $point['lat'], $point['lng']
            ) <= self::EXISTING_ELEMENT_TOLERANCE_M);
            if ($nearby) {
                continue;
            }

            $existing->push(ProjectAppendixItem::create([
                'project_id' => $projectId,
                'type' => $appendixType,
                'quantity' => $appendixType === 'boring_fi_130' ? 0 : 1,
                'unit' => $appendixType === 'boring_fi_130' ? 'metara' : 'KOMADA',
                'latitude' => $point['lat'],
                'longitude' => $point['lng'],
                'note' => 'Geodetski snimak',
                'import_batch' => $batch,
            ]));
            $created++;
        }

        return $created;
    }

    private function nearestWithin($models, array $point, float $maxMeters)
    {
        $best = null;
        $bestDistance = INF;
        foreach ($models as $model) {
            $distance = $this->geometry->distanceMeters((float) $model->latitude, (float) $model->longitude, $point[0], $point[1]);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $model;
            }
        }

        return $bestDistance <= $maxMeters ? $best : null;
    }

    private function cabinetTag(string $name): ?string
    {
        $n = strtr(mb_strtolower($name), ['š' => 's']);
        if (preg_match('/z(?:\s*[o0](?:rmar)?)?[\s\-_.]*([0-9]+(?:[.\-][0-9]+)*)/', $n, $m)) {
            return $this->codeNormalizer->cabinetTag($m[1]);
        }

        return null;
    }

    private function cabinetLabel(string $code): string
    {
        $n = trim(preg_replace('/\s+/', ' ', $code) ?? '');
        if (preg_match('/(?:zeleni\s*ormar|z\s*(?:[o0]\s*)?ormar|z\s*[o0])[\s\-_.]*([\d.]+)?/iu', strtr(mb_strtolower($n), ['š' => 's']), $m)) {
            return 'ZO'.(isset($m[1]) && $m[1] !== '' ? '-'.rtrim($m[1], '.') : '');
        }

        return $n !== '' ? $n : 'ZO';
    }

    private function mergeOdfPoints(array $points): array
    {
        $merged = [];
        foreach (collect($points)->where('kind', 'odf') as $point) {
            foreach ($merged as $existing) {
                if ($this->geometry->distanceMeters($existing['lat'], $existing['lng'], $point['lat'], $point['lng']) <= self::ODF_MERGE_M) {
                    continue 2;
                }
            }
            $merged[] = ['code' => $point['code'], 'lat' => $point['lat'], 'lng' => $point['lng']];
        }

        return $merged;
    }

    private function existsNearby(string $model, int $projectId, float $lat, float $lng): bool
    {
        return $model::query()
            ->where('project_id', $projectId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['latitude', 'longitude'])
            ->contains(fn ($row) => $this->geometry->distanceMeters(
                (float) $row->latitude, (float) $row->longitude, $lat, $lng
            ) <= self::EXISTING_ELEMENT_TOLERANCE_M);
    }

    private function uniqueName(string $model, int $projectId, string $base): string
    {
        $base = trim($base) !== '' ? trim($base) : 'Element';
        if (! $model::query()->where('project_id', $projectId)->where('name', $base)->exists()) {
            return $base;
        }
        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! $model::query()->where('project_id', $projectId)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Nije moguce generisati jedinstven naziv.');
    }

    private function uniqueHouseLabel(int $projectId, string $base): string
    {
        if (! House::where('project_id', $projectId)->where('label', $base)->exists()) {
            return $base;
        }
        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! House::where('project_id', $projectId)->where('label', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Nije moguce generisati jedinstvenu oznaku kuce.');
    }
}

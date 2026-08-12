<?php

namespace App\Services;

class SurveyColoredFlowService
{
    public function __construct(
        private readonly GeometryService $geometry,
        private readonly SurveyDuctIdentityService $ductIdentity,
        private readonly SurveyChainWalker $chainWalker,
    ) {}

    public function process(
        array $ducts,
        array $trenches,
        array $points,
        float $nodeMergeMeters,
        float $elementToleranceMeters,
        float $endpointBindMeters,
        float $trenchGapMeters,
    ): array {
        $ducts = $this->reconstructColoredFlows($ducts, $trenches, $points, $endpointBindMeters, $trenchGapMeters);
        $ducts = $this->anchorColoredPathsToOdfs($ducts, $points, 5.0);
        $ducts = $this->ensureObservedOdfColorStarts($ducts, $points, 5.0);
        $ducts = $this->distributeOdfBundleCounts($ducts, $points);
        $ducts = $this->connectColoredCountTransitions($ducts, $points, 10.0);
        $ducts = $this->ductIdentity->inferImplicitCabinetTags($ducts, $nodeMergeMeters);

        return $this->routeTaggedColoredDuctsThroughCabinets($ducts, $points, $elementToleranceMeters, $endpointBindMeters);
    }

    /**
     * A serial coloured distribution duct physically enters every surveyed cabinet and
     * the following section leaves from that exact same cabinet coordinate. Survey points
     * normally run along the trench beside the cabinet, so make the short in/out detour
     * explicit in route geometry. Explicit TRANZIT ducts are intentionally excluded.
     */
    private function routeTaggedColoredDuctsThroughCabinets(
        array $ducts,
        array $points,
        float $elementToleranceMeters,
        float $endpointBindMeters,
    ): array {
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

                if ($startDistance <= $elementToleranceMeters) {
                    $ducts[$index]['path'][0] = $coordinate;

                    continue;
                }
                if ($endDistance <= $elementToleranceMeters) {
                    $ducts[$index]['path'][$lastIndex] = $coordinate;

                    continue;
                }

                $projection = $this->geometry->projectPointToPath($coordinate, $path);
                if ($projection['distance_m'] > $endpointBindMeters) {
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
                    $ducts[$index]['label'] = $this->ductIdentity->label([
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
                foreach ($this->ductIdentity->identitiesAt($point) as $key => $attrs) {
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
                        'label' => $this->ductIdentity->label($attrs, (int) $attrs['count']),
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
    public function connectColoredCountTransitions(array $ducts, array $points, float $toleranceM): array
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
                    $projection = $this->geometry->projectPointToPath($endpoint, $ducts[$trunkIndex]['path']);
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

    /**
     * Descriptions are observations, not a full inventory at every point. Rebuild each
     * coloured 14/10 flow over the physical trench graph between consecutive observations,
     * so an intervening point that mentions only another colour does not cut this duct.
     */
    private function reconstructColoredFlows(
        array $ducts,
        array $trenches,
        array $points,
        float $endpointBindMeters,
        float $trenchGapMeters,
    ): array {
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
            foreach ($this->ductIdentity->identitiesAt($point) as $key => $attrs) {
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

        $nearestNode = function (array $point) use (&$nodes, $endpointBindMeters): ?string {
            $bestKey = null;
            $bestDistance = INF;
            foreach ($nodes as $key => $candidate) {
                $distance = $this->geometry->distanceBetweenPoints($point, $candidate);
                if ($distance < $bestDistance) {
                    $bestKey = $key;
                    $bestDistance = $distance;
                }
            }

            return $bestDistance <= $endpointBindMeters ? $bestKey : null;
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
                    $intermediateIdentities = $this->ductIdentity->identitiesAt($points[$sourceIndex]);
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
                $path = $hasOtherColourDetour && $directDistance <= $trenchGapMeters
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
            foreach ($this->chainWalker->walk($integerEdges, fn (array $edge) => $edge['count']) as $chain) {
                if (count($chain['nodes']) < 2) {
                    continue;
                }
                $path = array_map(fn (int $node) => $flowNodes[$node], $chain['nodes']);
                $chainCount = $integerEdges[$chain['edges'][0]]['count'];
                $rebuilt[] = [
                    'key' => $key,
                    'label' => $this->ductIdentity->label($attrs, $chainCount),
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

    private function pathEndpoints(array $path): array
    {
        return [$path[0], $path[count($path) - 1]];
    }
}

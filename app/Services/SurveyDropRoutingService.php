<?php

namespace App\Services;

class SurveyDropRoutingService
{
    private const NODE_MERGE_M = 1.5;

    private const EXISTING_ELEMENT_TOLERANCE_M = 5.0;

    private const DUCT_ENDPOINT_BIND_M = 30.0;

    private const CUSTOMER_SPUR_TO_TRENCH_M = 60.0;

    /** A recorded drop may end one normal survey interval before the shared route. */
    private const PEER_ROUTE_JOIN_M = 20.0;

    public function __construct(
        private readonly GeometryService $geometry,
        private readonly SurveyDuctIdentityService $ductIdentity,
        private readonly SurveyImportIdentityService $identity,
    ) {}

    public function process(
        array $ducts,
        array $routingTrenches,
        array $cabinetRoutingTrenches,
        array $points,
        array $bindingPoints,
    ): array {
        $ducts = $this->createImplicitTaggedDrops($ducts, $routingTrenches, $points);
        $ducts = $this->attachDropMetadata($ducts, $points);
        $ducts = $this->routeTaggedDropsThroughTrenches($ducts, $cabinetRoutingTrenches, $bindingPoints);

        return $this->retainTerminalCustomerDrops($ducts, $points);
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

            // When an implicit house sits exactly on the end of a newly surveyed
            // trench chain, that whole chain is its recorded access geometry. Keep
            // every TXT vertex instead of reducing it to a one-segment stub and later
            // allowing a shortest-path shortcut across an older saved route.
            $surveyAccessPath = null;
            foreach ($trenches as $trench) {
                $candidatePath = array_values($trench['path'] ?? []);
                if (($trench['_routing_source'] ?? 'survey') !== 'survey' || count($candidatePath) < 2) {
                    continue;
                }
                $firstDistance = $this->geometry->distanceMeters(
                    $terminal['lat'], $terminal['lng'], $candidatePath[0][0], $candidatePath[0][1]
                );
                $last = end($candidatePath);
                $lastDistance = $this->geometry->distanceMeters(
                    $terminal['lat'], $terminal['lng'], $last[0], $last[1]
                );
                if (min($firstDistance, $lastDistance) > 0.5) {
                    continue;
                }
                if ($lastDistance < $firstDistance) {
                    $candidatePath = array_reverse($candidatePath);
                }
                $candidatePath[0] = [round((float) $terminal['lat'], 7), round((float) $terminal['lng'], 7)];
                // Do not make this customer visit another customer's leaf and come
                // back. Remove that side spur and continue from its nearest preceding
                // junction (T1682: 1570 -> 1574, not 1570 -> 1573 -> 1574).
                foreach ($points as $otherTerminal) {
                    if (($otherTerminal['kind'] ?? null) !== 'sling'
                        || (int) $otherTerminal['point_no'] === (int) $terminal['point_no']) {
                        continue;
                    }
                    for ($pathIndex = 1; $pathIndex < count($candidatePath) - 1; $pathIndex++) {
                        if ($this->geometry->distanceMeters(
                            $otherTerminal['lat'], $otherTerminal['lng'],
                            $candidatePath[$pathIndex][0], $candidatePath[$pathIndex][1]
                        ) > 0.5) {
                            continue;
                        }
                        $nextPoint = $candidatePath[$pathIndex + 1];
                        $junctionIndex = $pathIndex - 1;
                        $junctionDistance = INF;
                        for ($previousIndex = 0; $previousIndex < $pathIndex; $previousIndex++) {
                            $distance = $this->geometry->distanceBetweenPoints($candidatePath[$previousIndex], $nextPoint);
                            if ($distance < $junctionDistance) {
                                $junctionDistance = $distance;
                                $junctionIndex = $previousIndex;
                            }
                        }
                        array_splice($candidatePath, $junctionIndex + 1, $pathIndex - $junctionIndex);
                        break 2;
                    }
                }
                $surveyAccessPath = $this->geometry->compactPath($candidatePath);
                break;
            }

            $nearest = null;
            $nearestDistance = INF;
            $coincident = null;
            foreach ($trenchVertices as $vertex) {
                $distance = $this->geometry->distanceMeters($terminal['lat'], $terminal['lng'], $vertex[0], $vertex[1]);
                // A previously saved path can contain the terminal coordinate itself.
                // Using it creates T -> T (0 m), which has no edge into the graph.
                // Prefer the next real surveyed trench vertex (e.g. T1682 -> 1567).
                if ($distance <= 0.5) {
                    $coincident ??= $vertex;

                    continue;
                }
                if ($distance < $nearestDistance) {
                    $nearest = $vertex;
                    $nearestDistance = $distance;
                }
            }
            if ($nearest === null) {
                $nearest = $coincident;
                $nearestDistance = $nearest === null ? INF : 0.0;
            }
            if ($nearest === null || $nearestDistance > self::DUCT_ENDPOINT_BIND_M) {
                continue;
            }

            $implicitPath = $surveyAccessPath ?? [[$terminal['lat'], $terminal['lng']], $nearest];
            $ducts[] = [
                'key' => '10/8|zo:'.$terminal['zo_tag'].'|point:'.$terminal['point_no'],
                'label' => $this->ductIdentity->label([
                    'type' => '10/8',
                    'color' => $terminal['colors'][0] ?? null,
                    'tag' => $terminal['zo_tag'],
                ], max(1, (int) $terminal['microduct_count'])).' (T'.$terminal['point_no'].')',
                'microduct_type' => '10/8',
                'microduct_count' => max(1, (int) $terminal['microduct_count']),
                'color' => $terminal['colors'][0] ?? null,
                'zo_tag' => $terminal['zo_tag'],
                'path' => $implicitPath,
                'length_m' => $this->geometry->polylineLength($implicitPath),
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
                // Use the same strict identity threshold as the rendered trench graph.
                // Distinct field readings such as T1626 and T1628 (about 1.42 m apart)
                // must remain separate or T1627/T1628 disappear from the red route.
                $mergeDistance = 0.5;
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

        $existingPaths = array_values(array_filter(
            $trenches,
            fn (array $trench) => ($trench['_routing_source'] ?? null) === 'existing'
                && count($trench['path'] ?? []) >= 2
        ));
        foreach ($trenches as $trench) {
            $addPathToGraph(
                $trench['path'],
                ($trench['_routing_source'] ?? null) !== 'existing'
            );
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
        $conformToSurveyGeometry = function (array $path) use (
            $trenches,
            $pathBetweenProjections,
            $trenchNodes,
            $trenchAdjacency,
        ): array {
            $path = array_values($path);
            if (count($path) < 2) {
                return $path;
            }
            $result = [$path[0]];
            for ($index = 1; $index < count($path); $index++) {
                $from = $path[$index - 1];
                $to = $path[$index];
                $best = null;
                foreach ($trenches as $trench) {
                    $surveyPath = $trench['path'] ?? [];
                    if (count($surveyPath) < 2) {
                        continue;
                    }
                    $fromProjection = $this->geometry->projectPointToPath($from, $surveyPath);
                    $toProjection = $this->geometry->projectPointToPath($to, $surveyPath);
                    if ($fromProjection['distance_m'] > self::EXISTING_ELEMENT_TOLERANCE_M
                        || $toProjection['distance_m'] > self::EXISTING_ELEMENT_TOLERANCE_M) {
                        continue;
                    }
                    $subpath = $pathBetweenProjections($surveyPath, $fromProjection, $toProjection);
                    $directLength = $this->geometry->distanceBetweenPoints($from, $to);
                    $subpathLength = $this->geometry->polylineLength($subpath);
                    if ($subpathLength > max(5.0, $directLength * 2.5)) {
                        continue;
                    }
                    $score = $fromProjection['distance_m'] + $toProjection['distance_m'];
                    if ($best === null || $score < $best['score']) {
                        $best = ['score' => $score, 'path' => $subpath];
                    }
                }
                // The two ends may lie on different, but connected, black trench
                // polylines. In that case follow the trench graph between them instead
                // of drawing a straight bridge across the space between the lines.
                if ($best === null) {
                    $fromNode = null;
                    $toNode = null;
                    $fromDistance = INF;
                    $toDistance = INF;
                    foreach ($trenchNodes as $nodeKey => $nodePoint) {
                        $candidateFrom = $this->geometry->distanceBetweenPoints($from, $nodePoint);
                        if ($candidateFrom < $fromDistance) {
                            $fromDistance = $candidateFrom;
                            $fromNode = $nodeKey;
                        }
                        $candidateTo = $this->geometry->distanceBetweenPoints($to, $nodePoint);
                        if ($candidateTo < $toDistance) {
                            $toDistance = $candidateTo;
                            $toNode = $nodeKey;
                        }
                    }
                    if ($fromNode !== null && $toNode !== null
                        && $fromDistance <= self::EXISTING_ELEMENT_TOLERANCE_M
                        && $toDistance <= self::EXISTING_ELEMENT_TOLERANCE_M) {
                        $networkDistance = [$fromNode => 0.0];
                        $networkPrevious = [];
                        $networkQueue = [[$fromNode, 0.0]];
                        while ($networkQueue) {
                            usort($networkQueue, fn (array $left, array $right) => $left[1] <=> $right[1]);
                            [$currentNode, $currentDistance] = array_shift($networkQueue);
                            if ($currentDistance > ($networkDistance[$currentNode] ?? INF)) {
                                continue;
                            }
                            if ($currentNode === $toNode) {
                                break;
                            }
                            foreach ($trenchAdjacency[$currentNode] ?? [] as [$nextNode, $weight]) {
                                $candidateDistance = $currentDistance + $weight;
                                if ($candidateDistance < ($networkDistance[$nextNode] ?? INF)) {
                                    $networkDistance[$nextNode] = $candidateDistance;
                                    $networkPrevious[$nextNode] = $currentNode;
                                    $networkQueue[] = [$nextNode, $candidateDistance];
                                }
                            }
                        }
                        $directLength = $this->geometry->distanceBetweenPoints($from, $to);
                        if (isset($networkDistance[$toNode])
                            && $networkDistance[$toNode] <= max(10.0, $directLength * 4.0)) {
                            $nodeKeys = [$toNode];
                            while (isset($networkPrevious[end($nodeKeys)])) {
                                $nodeKeys[] = $networkPrevious[end($nodeKeys)];
                            }
                            $networkPath = array_map(
                                fn (string $nodeKey) => $trenchNodes[$nodeKey],
                                array_reverse($nodeKeys),
                            );
                            $best = [
                                'score' => $fromDistance + $toDistance,
                                'path' => array_merge([$from], $networkPath, [$to]),
                            ];
                        }
                    }
                }
                if ($best === null) {
                    $result[] = $to;

                    continue;
                }
                $replacement = $best['path'];
                $replacement[0] = $from;
                $replacement[count($replacement) - 1] = $to;
                $result = array_merge($result, array_slice($replacement, 1));
            }

            return $this->geometry->compactPath($result);
        };
        $orientOwnPathFromTerminal = function (array $path, array $terminal): array {
            $path = array_values($path);
            if (count($path) < 2) {
                return $path;
            }
            $firstDistance = $this->geometry->distanceBetweenPoints($terminal, $path[0]);
            $lastDistance = $this->geometry->distanceBetweenPoints($terminal, end($path));
            if ($lastDistance < $firstDistance) {
                $path = array_reverse($path);
            }
            if ($this->geometry->distanceBetweenPoints($terminal, $path[0]) > 0.5) {
                array_unshift($path, $terminal);
            } else {
                $path[0] = $terminal;
            }

            return $this->geometry->compactPath($path);
        };
        $extendAcrossNearbySurveyBranch = function (array $ownPath) use ($trenches): array {
            if ($ownPath === []) {
                return $ownPath;
            }
            $entry = end($ownPath);
            $best = null;
            foreach ($trenches as $trench) {
                if (($trench['_routing_source'] ?? 'survey') !== 'survey') {
                    continue;
                }
                $candidate = array_values($trench['path'] ?? []);
                if (count($candidate) < 2) {
                    continue;
                }
                $firstDistance = $this->geometry->distanceBetweenPoints($entry, $candidate[0]);
                $lastDistance = $this->geometry->distanceBetweenPoints($entry, end($candidate));
                $distance = min($firstDistance, $lastDistance);
                if ($distance <= 0.5 || $distance > self::EXISTING_ELEMENT_TOLERANCE_M
                    || ($best !== null && $distance >= $best['distance'])) {
                    continue;
                }
                if ($lastDistance < $firstDistance) {
                    $candidate = array_reverse($candidate);
                }
                $best = ['distance' => $distance, 'path' => $candidate];
            }
            if ($best === null) {
                return $ownPath;
            }

            return $this->geometry->compactPath(array_merge($ownPath, $best['path']));
        };

        foreach ($ducts as &$duct) {
            if ($duct['microduct_type'] !== '10/8' || $duct['zo_tag'] === null || count($duct['path']) < 2) {
                continue;
            }
            $duct['cabinet_reached'] = false;
            $cabinet = $cabinets->first(fn ($point) => $this->identity->cabinetTag($point['code']) === $duct['zo_tag']);
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

            // Route over the same graph that is rendered as the black trench. The
            // coloured duct reconstruction can legitimately be shorter than the dig
            // (because a label is omitted on shared points), but it must never be used
            // as geometric authority for drawing the customer route.
            $startNode = null;
            $startDistance = INF;
            foreach ($trenchNodes as $nodeKey => $nodePoint) {
                if (isset($terminalGraphNodes[$nodeKey])
                    && ! in_array((int) $terminal['point_no'], $terminalGraphNodes[$nodeKey], true)) {
                    continue;
                }
                $candidateDistance = $this->geometry->distanceBetweenPoints($terminalCoordinate, $nodePoint);
                if ($candidateDistance < $startDistance) {
                    $startDistance = $candidateDistance;
                    $startNode = $nodeKey;
                }
            }
            // This mode is only valid when the house is physically on its surveyed
            // access trench. A distant nearest node is not evidence of connectivity.
            if ($startNode !== null && $startDistance <= self::EXISTING_ELEMENT_TOLERANCE_M) {
                $distanceTo = [$startNode => 0.0];
                $previousNode = [];
                $queue = [[$startNode, 0.0]];
                while ($queue !== []) {
                    usort($queue, fn (array $left, array $right) => $left[1] <=> $right[1]);
                    [$currentNode, $currentDistance] = array_shift($queue);
                    if ($currentDistance > ($distanceTo[$currentNode] ?? INF)) {
                        continue;
                    }
                    foreach ($trenchAdjacency[$currentNode] ?? [] as [$nextNode, $weight]) {
                        // A house/SLINGA is a leaf, never a transit junction for another
                        // customer's route. Crossing it creates the visible triangle:
                        // fork -> other house -> main route.
                        if (isset($terminalGraphNodes[$nextNode])
                            && ! in_array((int) $terminal['point_no'], $terminalGraphNodes[$nextNode], true)) {
                            continue;
                        }
                        $nextDistance = $currentDistance + $weight;
                        if ($nextDistance < ($distanceTo[$nextNode] ?? INF)) {
                            $distanceTo[$nextNode] = $nextDistance;
                            $previousNode[$nextNode] = $currentNode;
                            $queue[] = [$nextNode, $nextDistance];
                        }
                    }
                }
                $bestTrenchRoute = null;
                $cabinetCoordinate = [(float) $cabinet['lat'], (float) $cabinet['lng']];
                foreach ($existingPaths as $existingTrench) {
                    $existingPath = $existingTrench['path'] ?? [];
                    $cabinetProjection = $this->geometry->projectPointToPath($cabinetCoordinate, $existingPath);
                    if ($cabinetProjection['distance_m'] > self::DUCT_ENDPOINT_BIND_M) {
                        continue;
                    }
                    foreach ($distanceTo as $candidateNode => $networkDistance) {
                        $joinProjection = $this->geometry->projectPointToPath($trenchNodes[$candidateNode], $existingPath);
                        // Only a real intersection may transfer from black to blue;
                        // nearby parallel lines are not connected.
                        if ($joinProjection['distance_m'] > 0.75) {
                            continue;
                        }
                        $corridorPath = $pathBetweenProjections($existingPath, $joinProjection, $cabinetProjection);
                        // The first physical contact with the main route always wins.
                        // Distance remaining to the cabinet must never make us ignore
                        // that contact and continue wandering over the customer trench.
                        $score = $networkDistance * 1000.0
                            + $this->geometry->polylineLength($corridorPath);
                        if ($bestTrenchRoute === null || $score < $bestTrenchRoute['score']) {
                            $bestTrenchRoute = [
                                'score' => $score,
                                'join_node' => $candidateNode,
                                'join_projection' => [$joinProjection['lat'], $joinProjection['lng']],
                                'corridor_path' => $corridorPath,
                            ];
                        }
                    }
                }
                if ($bestTrenchRoute !== null) {
                    $nodeKeys = [$bestTrenchRoute['join_node']];
                    while (isset($previousNode[end($nodeKeys)])) {
                        $nodeKeys[] = $previousNode[end($nodeKeys)];
                    }
                    $blackPath = array_map(
                        fn (string $nodeKey) => $trenchNodes[$nodeKey],
                        array_reverse($nodeKeys),
                    );
                    $fullPath = array_merge([$terminalCoordinate], $blackPath);
                    if ($this->geometry->distanceBetweenPoints(end($fullPath), $bestTrenchRoute['join_projection']) > 0.2) {
                        $fullPath[] = $bestTrenchRoute['join_projection'];
                    }
                    $fullPath = array_merge($fullPath, $bestTrenchRoute['corridor_path']);
                    if ($this->geometry->distanceBetweenPoints(end($fullPath), $cabinetCoordinate) > 0.5) {
                        $fullPath[] = $cabinetCoordinate;
                    }
                    // Re-expand every graph edge over the recorded trench polyline so
                    // T1401 -> T1400 -> T1399 cannot become a diagonal shortcut.
                    $duct['path'] = $conformToSurveyGeometry($fullPath);
                    $duct['length_m'] = $this->geometry->polylineLength($duct['path']);
                    $duct['cabinet_reached'] = true;
                    $duct['routed_via_trench'] = true;

                    continue;
                }
            }

            // Preferred, fully evidenced route: keep the customer's own surveyed
            // geometry until it physically reaches an already saved main corridor,
            // then copy that corridor's exact geometry to the assigned cabinet.
            // This is the field topology: black customer branch -> blue 14/10 -> ZO.
            $orientedOwnPath = $orientOwnPathFromTerminal($duct['path'], $terminalCoordinate);
            $cabinetCoordinate = [(float) $cabinet['lat'], (float) $cabinet['lng']];
            $bestMainCorridor = null;
            foreach ($existingPaths as $existingTrench) {
                $existingPath = $existingTrench['path'] ?? [];
                if (count($existingPath) < 2) {
                    continue;
                }
                $cabinetProjection = $this->geometry->projectPointToPath($cabinetCoordinate, $existingPath);
                if ($cabinetProjection['distance_m'] > self::DUCT_ENDPOINT_BIND_M) {
                    continue;
                }
                // The whole recorded customer branch is authoritative. Joining from
                // an earlier vertex makes the red line skip the remaining black trench
                // merely because a parallel main happens to be close to it.
                $ownIndex = count($orientedOwnPath) - 1;
                $ownPoint = $orientedOwnPath[$ownIndex];
                $joinProjection = $this->geometry->projectPointToPath($ownPoint, $existingPath);
                if ($joinProjection['distance_m'] > self::EXISTING_ELEMENT_TOLERANCE_M) {
                    continue;
                }
                $corridorPath = $pathBetweenProjections($existingPath, $joinProjection, $cabinetProjection);
                $totalLength = $this->geometry->polylineLength($orientedOwnPath)
                    + $joinProjection['distance_m']
                    + $this->geometry->polylineLength($corridorPath)
                    + $cabinetProjection['distance_m'];
                // Physical contact is stronger evidence than a marginally shorter
                // route. This prevents a nearby parallel main from stealing a drop.
                $score = $joinProjection['distance_m'] * 1000.0 + $totalLength;
                if ($bestMainCorridor === null || $score < $bestMainCorridor['score']) {
                    $bestMainCorridor = [
                        'score' => $score,
                        'own_index' => $ownIndex,
                        'join_projection' => [$joinProjection['lat'], $joinProjection['lng']],
                        'corridor_path' => $corridorPath,
                    ];
                }
            }
            if ($bestMainCorridor !== null) {
                $fullPath = array_slice($orientedOwnPath, 0, $bestMainCorridor['own_index'] + 1);
                if ($this->geometry->distanceBetweenPoints(end($fullPath), $bestMainCorridor['join_projection']) > 0.5) {
                    $fullPath[] = $bestMainCorridor['join_projection'];
                }
                $fullPath = array_merge($fullPath, $bestMainCorridor['corridor_path']);
                if ($this->geometry->distanceBetweenPoints(end($fullPath), $cabinetCoordinate) > 0.5) {
                    $fullPath[] = $cabinetCoordinate;
                }
                $duct['path'] = $conformToSurveyGeometry($fullPath);
                $duct['length_m'] = $this->geometry->polylineLength($duct['path']);
                $duct['cabinet_reached'] = true;
                $duct['routed_via_trench'] = true;

                continue;
            }
            // A cluster of approximate house endpoints can place another house between
            // this terminal and the first real trench observation. Start each customer
            // independently at the nearest NON-terminal trench node; customer points
            // must never be used as somebody else's shared corridor.
            $joinNode = null;
            $joinDistance = INF;
            foreach ($nodes as $candidateKey => $candidatePoint) {
                // A zero-length implicit drop can sit exactly on a surveyed trench
                // vertex (T1682). Its own terminal node is a valid graph entry; only
                // terminal nodes belonging exclusively to other customers are unsafe.
                if (isset($terminalGraphNodes[$candidateKey])
                    && ! in_array((int) $terminal['point_no'], $terminalGraphNodes[$candidateKey], true)) {
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
                // A route may leave the graph directly toward the cabinet only when the
                // surveyed node is effectively the cabinet node. If it is merely nearby,
                // keep routing over the saved main corridor; otherwise a red customer
                // line cuts diagonally to ODO instead of following the black main line.
                $isCabinetTurn = false;
                $neighborKeys = array_values(array_unique(array_map(
                    fn (array $edge) => $edge[0],
                    $adjacency[$reachableNode] ?? [],
                )));
                usort($neighborKeys, fn (string $left, string $right) => $this->geometry->distanceBetweenPoints($nodes[$reachableNode], $nodes[$left])
                    <=> $this->geometry->distanceBetweenPoints($nodes[$reachableNode], $nodes[$right])
                );
                $neighborKeys = array_slice($neighborKeys, 0, 8);
                for ($left = 0; $left < count($neighborKeys) && ! $isCabinetTurn; $left++) {
                    for ($right = $left + 1; $right < count($neighborKeys); $right++) {
                        $leftPoint = $nodes[$neighborKeys[$left]];
                        $rightPoint = $nodes[$neighborKeys[$right]];
                        $leftCabinetDistance = $this->geometry->distanceBetweenPoints($leftPoint, [$cabinet['lat'], $cabinet['lng']]);
                        $rightCabinetDistance = $this->geometry->distanceBetweenPoints($rightPoint, [$cabinet['lat'], $cabinet['lng']]);
                        $throughTurn = $this->geometry->distanceBetweenPoints($leftPoint, $nodes[$reachableNode])
                            + $this->geometry->distanceBetweenPoints($nodes[$reachableNode], $rightPoint);
                        $withoutTurn = $this->geometry->distanceBetweenPoints($leftPoint, $rightPoint);
                        if ($candidateDistance < $leftCabinetDistance
                            && $candidateDistance < $rightCabinetDistance
                            && $throughTurn - $withoutTurn >= 3.0) {
                            $isCabinetTurn = true;
                            break;
                        }
                    }
                }
                if ($candidateDistance > self::NODE_MERGE_M && ! $isCabinetTurn) {
                    continue;
                }
                $candidateScore = $distanceFromTerminal + $candidateDistance;
                if ($candidateScore < $targetScore) {
                    $targetNode = $reachableNode;
                    $targetDistance = $candidateDistance;
                    $targetScore = $candidateScore;
                }
            }
            if ($targetNode === null || $targetDistance > self::DUCT_ENDPOINT_BIND_M) {
                // The new spur may touch the middle of a saved main route whose own graph
                // is split elsewhere. Finish explicitly as: house -> surveyed spur ->
                // projection on saved main -> saved main -> assigned ZO.
                $bestCorridor = null;
                $cabinetCoordinate = [(float) $cabinet['lat'], (float) $cabinet['lng']];
                foreach ($existingPaths as $existingTrench) {
                    $cabinetProjection = $this->geometry->projectPointToPath($cabinetCoordinate, $existingTrench['path']);
                    if ($cabinetProjection['distance_m'] > self::DUCT_ENDPOINT_BIND_M) {
                        continue;
                    }
                    foreach ($distance as $reachableNode => $distanceFromTerminal) {
                        if (isset($terminalGraphNodes[$reachableNode])) {
                            continue;
                        }
                        $joinProjection = $this->geometry->projectPointToPath($nodes[$reachableNode], $existingTrench['path']);
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
                    $duct['path'] = $conformToSurveyGeometry($fullPath);
                    $duct['length_m'] = $this->geometry->polylineLength($duct['path']);
                    $duct['cabinet_reached'] = true;
                    $duct['routed_via_trench'] = true;
                }

                // Legacy graph reconstruction used by the proven test_koordinate
                // project: an isolated approximate terminal may join only an already
                // completed route for the same ZO. With graph-built trenches this is a
                // last-resort continuation, not the primary topology builder.
                if (! ($duct['cabinet_reached'] ?? false)) {
                    $ownPath = $extendAcrossNearbySurveyBranch(
                        $orientOwnPathFromTerminal($duct['path'], $terminalCoordinate)
                    );
                    $entryCoordinate = end($ownPath);
                    $bestPeer = null;
                    foreach ($ducts as $peer) {
                        if (! ($peer['cabinet_reached'] ?? false)
                            || ($peer['microduct_type'] ?? null) !== '10/8'
                            || ($peer['zo_tag'] ?? null) !== $duct['zo_tag']
                            || count($peer['path'] ?? []) < 2) {
                            continue;
                        }
                        $projection = $this->geometry->projectPointToPath($entryCoordinate, $peer['path']);
                        if ($projection['distance_m'] > self::PEER_ROUTE_JOIN_M
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
                        $sharedPath = $conformToSurveyGeometry($sharedPath);
                        $fullPath = array_merge($ownPath, $sharedPath);
                        if ($this->geometry->distanceBetweenPoints(end($fullPath), $cabinetCoordinate) > 0.5) {
                            $fullPath[] = $cabinetCoordinate;
                        }
                        $duct['path'] = $conformToSurveyGeometry($fullPath);
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
            $duct['path'] = $conformToSurveyGeometry($fullPath);
            $duct['length_m'] = $this->geometry->polylineLength($duct['path']);
            $duct['cabinet_reached'] = true;
            $duct['routed_via_trench'] = true;
        }
        unset($duct);

        // Resolve order-independent peer continuations only after every directly
        // provable customer route has had a chance to reach its cabinet. Previously an
        // early house could not use a valid same-ZO corridor that appeared later in the
        // TXT, while reversing the records produced a different map.
        foreach ($ducts as &$duct) {
            if (($duct['cabinet_reached'] ?? false)
                || ($duct['microduct_type'] ?? null) !== '10/8'
                || ($duct['zo_tag'] ?? null) === null
                || count($duct['path'] ?? []) < 2) {
                continue;
            }
            $terminal = isset($duct['_terminal_point'])
                ? collect($points)->firstWhere('point_no', (int) $duct['_terminal_point'])
                : null;
            $cabinet = $cabinets->first(fn ($point) => $this->identity->cabinetTag($point['code']) === $duct['zo_tag']);
            if ($terminal === null || $cabinet === null) {
                continue;
            }
            $terminalCoordinate = [round((float) $terminal['lat'], 7), round((float) $terminal['lng'], 7)];
            $ownPath = $extendAcrossNearbySurveyBranch(
                $orientOwnPathFromTerminal($duct['path'], $terminalCoordinate)
            );
            $entryCoordinate = end($ownPath);
            $bestPeer = null;
            foreach ($ducts as $peer) {
                if (! ($peer['cabinet_reached'] ?? false)
                    || ($peer['microduct_type'] ?? null) !== '10/8'
                    || ($peer['zo_tag'] ?? null) !== $duct['zo_tag']
                    || count($peer['path'] ?? []) < 2) {
                    continue;
                }
                $projection = $this->geometry->projectPointToPath($entryCoordinate, $peer['path']);
                if ($projection['distance_m'] > self::PEER_ROUTE_JOIN_M
                    || ($bestPeer !== null && $projection['distance_m'] >= $bestPeer['distance_m'])) {
                    continue;
                }
                $bestPeer = $projection + ['path' => $peer['path']];
            }
            if ($bestPeer === null) {
                continue;
            }
            $peerPath = $bestPeer['path'];
            $projectionPoint = [$bestPeer['lat'], $bestPeer['lng']];
            $segmentIndex = max(1, min((int) $bestPeer['segment_index'], count($peerPath) - 1));
            $cabinetCoordinate = [(float) $cabinet['lat'], (float) $cabinet['lng']];
            $cabinetAtStart = $this->geometry->distanceBetweenPoints($peerPath[0], $cabinetCoordinate)
                <= $this->geometry->distanceBetweenPoints(end($peerPath), $cabinetCoordinate);
            $sharedPath = $cabinetAtStart
                ? array_merge([$projectionPoint], array_reverse(array_slice($peerPath, 0, $segmentIndex)))
                : array_merge([$projectionPoint], array_slice($peerPath, $segmentIndex));
            $sharedPath = $conformToSurveyGeometry($sharedPath);
            $fullPath = array_merge($ownPath, $sharedPath);
            if ($this->geometry->distanceBetweenPoints(end($fullPath), $cabinetCoordinate) > 0.5) {
                $fullPath[] = $cabinetCoordinate;
            }
            $duct['path'] = $conformToSurveyGeometry($fullPath);
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

    private function pathEndpoints(array $path): array
    {
        return [$path[0], $path[count($path) - 1]];
    }
}

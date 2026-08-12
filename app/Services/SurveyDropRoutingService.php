<?php

namespace App\Services;

class SurveyDropRoutingService
{
    private const NODE_MERGE_M = 1.5;

    private const EXISTING_ELEMENT_TOLERANCE_M = 5.0;

    private const DUCT_ENDPOINT_BIND_M = 30.0;

    private const CUSTOMER_SPUR_TO_TRENCH_M = 60.0;

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
                'label' => $this->ductIdentity->label([
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
                        $projection = $this->geometry->projectPointToPath($endpoint, $existingTrench['path']);
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
                        $projection = $this->geometry->projectPointToPath($terminalCoordinate, $peer['path']);
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

    private function pathEndpoints(array $path): array
    {
        return [$path[0], $path[count($path) - 1]];
    }
}

<?php

namespace App\Services;

class SurveyGraphBuilder
{
    public function __construct(
        private readonly GeometryService $geometry,
        private readonly SurveyDuctIdentityService $ductIdentity,
    ) {}

    /**
     * Build a graph from ordered survey points by merging nearby nodes and
     * creating edges between consecutive points in the original walk.
     *
     * @return array{0: array<int,array<float,float>>,1: array,2: array<int,bool>} nodes, edges,
     *                                                                             and the subset of node indices that are a house or reserve loop ('sling'/'loop')
     */
    public function build(
        array $points,
        float $nodeMergeMeters,
        float $customerSpurMeters,
        float $taggedDuctGapMeters,
        float $trenchGapMeters,
        array $knownTerminalCoordinates = [],
    ): array {
        $count = count($points);
        if ($count < 2) {
            return [[], [], []];
        }

        $parent = range(0, $count - 1);
        $clusterMembers = array_map(fn (int $index) => [$index], range(0, $count - 1));
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
                // Preserve distinct field observations even when they are less than
                // 1.5 m apart (1569 and 1570). Only near-identical readings are the
                // same graph node; this still collapses true duplicates such as
                // 1460/1461.
                $mergeDistance = min($nodeMergeMeters, 0.5);
                $rootI = $find($i);
                $rootJ = $find($j);
                $withinWholeCluster = collect($clusterMembers[$rootJ] ?? [$j])->every(
                    fn (int $member) => $this->geometry->distanceMeters(
                        $points[$i]['lat'], $points[$i]['lng'],
                        $points[$member]['lat'], $points[$member]['lng']
                    ) <= $mergeDistance
                );
                if ($withinWholeCluster && $this->geometry->distanceMeters(
                    $points[$i]['lat'], $points[$i]['lng'],
                    $points[$j]['lat'], $points[$j]['lng']
                ) <= $mergeDistance
                    && (! $iIsTerminal || ($points[$i]['zo_tag'] ?? null) === ($points[$j]['zo_tag'] ?? null))) {
                    if ($rootI !== $rootJ) {
                        $parent[$rootJ] = $rootI;
                        $clusterMembers[$rootI] = array_values(array_unique(array_merge(
                            $clusterMembers[$rootI] ?? [$i],
                            $clusterMembers[$rootJ] ?? [$j],
                        )));
                        unset($clusterMembers[$rootJ]);
                    }
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
                $terminalIdents = $this->ductIdentity->identitiesAt($points[$terminalIndex]);
                $candidates = [];

                foreach ([-1, 1] as $direction) {
                    for ($j = $terminalIndex + $direction; $j >= 0 && $j < $count; $j += $direction) {
                        if (($points[$j]['_segment_no'] ?? 0) !== ($points[$terminalIndex]['_segment_no'] ?? 0)) {
                            break;
                        }
                        if (in_array($points[$j]['kind'], ['sling', 'loop'], true)) {
                            continue;
                        }

                        $candidateIdents = $this->ductIdentity->identitiesAt($points[$j]);
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
                        if ($nodeOf[$j] !== $terminalNode && $distance <= $customerSpurMeters) {
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
        $terminalCoordinates = array_merge($knownTerminalCoordinates, array_values(array_map(
            fn (array $point) => [$point['lat'], $point['lng']],
            array_filter($points, fn (array $point) => in_array($point['kind'], ['sling', 'loop'], true))
        )));
        $returnLatCell = 10 / 111320;
        $returnLngCell = 10 / (111320 * cos(deg2rad($points[0]['lat'])));
        foreach ($points as $pointIndex => $point) {
            $key = ((int) floor($point['lat'] / $returnLatCell)).'|'.((int) floor($point['lng'] / $returnLngCell));
            $returnBuckets[$key][] = $pointIndex;
        }

        for ($i = 1; $i < $count; $i++) {
            // A blank line in the TXT explicitly ends one recorded branch. Never
            // invent an edge from its last point to the first point of the next block.
            if (($points[$i - 1]['_segment_no'] ?? 0) !== ($points[$i]['_segment_no'] ?? 0)) {
                continue;
            }
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
            $toIdents = $this->ductIdentity->identitiesAt($points[$i]);
            $followsTerminal = in_array($points[$i - 1]['kind'], ['sling', 'loop'], true)
                || collect($terminalCoordinates)->contains(
                    fn (array $terminalCoordinate) => $this->geometry->distanceMeters(
                        $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                        $terminalCoordinate[0], $terminalCoordinate[1]
                    ) <= 0.5
                );
            $returnSearchRadius = $followsTerminal ? $customerSpurMeters : 10.0;
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
                    $this->ductIdentity->identitiesAt($points[$j]),
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
                    // A normal short surveyed bend must retain every consecutive
                    // vertex (1453 -> 1463 -> 1462 -> 1460). Re-anchoring is only
                    // justified after a real recording jump, not within the usual
                    // <= 10 m spacing between field observations.
                    || ($gap > 10.0 && $returnDistance <= 10.0 && $returnDistance + 2.0 < $gap));
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

            $fromIdents = $this->ductIdentity->identitiesAt($points[$fromPointIndex]);
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
                ? $customerSpurMeters
                : (($followsTerminal && $returnNode !== null)
                    ? $customerSpurMeters
                    : (($hasSameTaggedCustomerDuct || $hasTransitDuct) && ! $touchesCustomerTerminal
                    ? $taggedDuctGapMeters
                    : $trenchGapMeters));
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
}

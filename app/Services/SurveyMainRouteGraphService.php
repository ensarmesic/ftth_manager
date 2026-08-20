<?php

namespace App\Services;

use InvalidArgumentException;
use SplPriorityQueue;

class SurveyMainRouteGraphService
{
    /** Five centimetres: only coordinate precision, never corridor proximity. */
    public const NODE_MATCH_TOLERANCE_M = 0.05;

    public function __construct(private readonly GeometryService $geometry) {}

    /**
     * Build a strict graph whose every edge is one existing polyline segment.
     *
     * @param  array<int,array{id:int|string,path:array<int,array{0:float|int,1:float|int}>}>  $routes
     * @return array{nodes:array<string,array{0:float,1:float}>,edges:array<string,array<string,array{weight_m:float,sources:array}>>}
     */
    public function build(array $routes, float $nodeToleranceMeters = self::NODE_MATCH_TOLERANCE_M): array
    {
        if ($nodeToleranceMeters < 0 || $nodeToleranceMeters > self::NODE_MATCH_TOLERANCE_M) {
            throw new InvalidArgumentException('Tolerancija grafa mora biti između 0 i 0.05 m.');
        }

        $nodes = [];
        $edges = [];
        $segments = [];

        foreach ($routes as $route) {
            $path = array_values($route['path'] ?? []);
            if (count($path) < 2 || ! array_key_exists('id', $route)) {
                continue;
            }

            for ($segmentIndex = 1; $segmentIndex < count($path); $segmentIndex++) {
                $segments[] = [
                    'route_id' => $route['id'],
                    'segment_index' => $segmentIndex - 1,
                    'from' => [(float) $path[$segmentIndex - 1][0], (float) $path[$segmentIndex - 1][1]],
                    'to' => [(float) $path[$segmentIndex][0], (float) $path[$segmentIndex][1]],
                    'cuts' => [
                        ['fraction' => 0.0, 'point' => [(float) $path[$segmentIndex - 1][0], (float) $path[$segmentIndex - 1][1]]],
                        ['fraction' => 1.0, 'point' => [(float) $path[$segmentIndex][0], (float) $path[$segmentIndex][1]]],
                    ],
                ];
            }
        }

        foreach ($this->intersectionCandidates($segments) as [$left, $right]) {
            foreach ($this->segmentConnections($segments[$left], $segments[$right], $nodeToleranceMeters) as $intersection) {
                $segments[$left]['cuts'][] = ['fraction' => $intersection['left_fraction'], 'point' => $intersection['point']];
                $segments[$right]['cuts'][] = ['fraction' => $intersection['right_fraction'], 'point' => $intersection['point']];
            }
        }

        foreach ($segments as $segment) {
            usort($segment['cuts'], fn (array $left, array $right) => $left['fraction'] <=> $right['fraction']);
            $cuts = [];
            foreach ($segment['cuts'] as $cut) {
                if ($cuts === [] || abs($cut['fraction'] - end($cuts)['fraction']) > 1e-10) {
                    $cuts[] = $cut;
                }
            }
            for ($index = 1; $index < count($cuts); $index++) {
                $previousKey = $this->nodeKey($nodes, $cuts[$index - 1]['point'], $nodeToleranceMeters);
                $currentKey = $this->nodeKey($nodes, $cuts[$index]['point'], $nodeToleranceMeters);
                if ($previousKey === $currentKey) {
                    continue;
                }
                $weight = $this->geometry->distanceBetweenPoints($nodes[$previousKey], $nodes[$currentKey]);
                $source = [
                    'edge_id' => $this->edgeId($cuts[$index - 1]['point'], $cuts[$index]['point']),
                    'route_id' => $segment['route_id'],
                    'segment_index' => $segment['segment_index'],
                    'from' => $cuts[$index - 1]['point'],
                    'to' => $cuts[$index]['point'],
                ];
                $this->addEdge($edges, $previousKey, $currentKey, $weight, $source);
                $this->addEdge($edges, $currentKey, $previousKey, $weight, $source);
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Find a path only when both endpoints are confirmed graph nodes.
     *
     * @return null|array{path:array,edge_sources:array,length_m:float}
     */
    public function shortestPath(array $graph, array $from, array $to, float $nodeToleranceMeters = self::NODE_MATCH_TOLERANCE_M): ?array
    {
        $start = $this->locateNode($graph, $from, $nodeToleranceMeters);
        $end = $this->locateNode($graph, $to, $nodeToleranceMeters);
        $start ??= $this->attachPointOnExistingEdge($graph, $from, $nodeToleranceMeters);
        $end ??= $this->attachPointOnExistingEdge($graph, $to, $nodeToleranceMeters);
        if ($start === null || $end === null) {
            return null;
        }

        $distance = [$start => 0.0];
        $previous = [];
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $queue->insert($start, 0.0);

        while (! $queue->isEmpty()) {
            $item = $queue->extract();
            $current = (string) $item['data'];
            $currentDistance = -(float) $item['priority'];
            if ($currentDistance > ($distance[$current] ?? INF)) {
                continue;
            }
            if ($current === $end) {
                break;
            }
            foreach (($graph['edges'][$current] ?? []) as $next => $edge) {
                $candidate = $currentDistance + $edge['weight_m'];
                if ($candidate < ($distance[$next] ?? INF)) {
                    $distance[$next] = $candidate;
                    $previous[$next] = $current;
                    $queue->insert($next, -$candidate);
                }
            }
        }

        if (! isset($distance[$end])) {
            return null;
        }

        $keys = [$end];
        while (end($keys) !== $start) {
            $keys[] = $previous[end($keys)];
        }
        $keys = array_reverse($keys);
        $sources = [];
        for ($index = 1; $index < count($keys); $index++) {
            $sources[] = $graph['edges'][$keys[$index - 1]][$keys[$index]]['sources'];
        }

        return [
            'path' => array_map(fn (string $key) => $graph['nodes'][$key], $keys),
            'edge_sources' => $sources,
            'length_m' => $distance[$end],
        ];
    }

    public function locateNode(array $graph, array $point, float $nodeToleranceMeters = self::NODE_MATCH_TOLERANCE_M): ?string
    {
        if ($nodeToleranceMeters < 0 || $nodeToleranceMeters > self::NODE_MATCH_TOLERANCE_M) {
            throw new InvalidArgumentException('Tolerancija grafa mora biti između 0 i 0.05 m.');
        }

        return $this->findNode($graph['nodes'] ?? [], $point, $nodeToleranceMeters);
    }

    private function nodeKey(array &$nodes, array $point, float $toleranceMeters): string
    {
        $normalized = [(float) $point[0], (float) $point[1]];
        $existing = $this->findNode($nodes, $normalized, $toleranceMeters);
        if ($existing !== null) {
            return $existing;
        }

        $key = sprintf('%.9F,%.9F', $normalized[0], $normalized[1]);
        while (isset($nodes[$key])) {
            $key .= '#';
        }
        $nodes[$key] = $normalized;

        return $key;
    }

    private function findNode(array $nodes, array $point, float $toleranceMeters): ?string
    {
        foreach ($nodes as $key => $node) {
            if ($this->geometry->distanceBetweenPoints($node, $point) <= $toleranceMeters) {
                return $key;
            }
        }

        return null;
    }

    private function addEdge(array &$edges, string $from, string $to, float $weight, array $source): void
    {
        if (! isset($edges[$from][$to])) {
            $edges[$from][$to] = ['weight_m' => $weight, 'sources' => [$source]];

            return;
        }

        $edges[$from][$to]['weight_m'] = min($edges[$from][$to]['weight_m'], $weight);
        $edges[$from][$to]['sources'][] = $source;
    }

    private function edgeId(array $from, array $to): string
    {
        $points = [
            sprintf('%.9F,%.9F', $from[0], $from[1]),
            sprintf('%.9F,%.9F', $to[0], $to[1]),
        ];
        sort($points, SORT_STRING);

        return sha1(implode('|', $points));
    }

    /** Split only an edge the point demonstrably lies on; never select a nearby route. */
    private function attachPointOnExistingEdge(array &$graph, array $point, float $toleranceMeters): ?string
    {
        $point = [(float) $point[0], (float) $point[1]];
        foreach ($graph['edges'] ?? [] as $fromKey => $neighbors) {
            foreach ($neighbors as $toKey => $edge) {
                if (strcmp($fromKey, $toKey) >= 0) {
                    continue;
                }
                $from = $graph['nodes'][$fromKey];
                $to = $graph['nodes'][$toKey];
                if ($this->geometry->distanceToSegment($point[0], $point[1], $from, $to) > $toleranceMeters) {
                    continue;
                }

                $key = sprintf('%.9F,%.9F', $point[0], $point[1]);
                while (isset($graph['nodes'][$key])) {
                    $key .= '#';
                }
                $graph['nodes'][$key] = $point;
                foreach ([[$fromKey, $from], [$toKey, $to]] as [$endpointKey, $endpoint]) {
                    $weight = $this->geometry->distanceBetweenPoints($point, $endpoint);
                    $source = $edge['sources'][0] + [
                        'edge_id' => $this->edgeId($point, $endpoint),
                        'from' => $point,
                        'to' => $endpoint,
                    ];
                    $this->addEdge($graph['edges'], $key, $endpointKey, $weight, $source);
                    $this->addEdge($graph['edges'], $endpointKey, $key, $weight, $source);
                }

                return $key;
            }
        }

        return null;
    }

    private function segmentConnections(array $left, array $right, float $toleranceMeters): array
    {
        $a = $left['from'];
        $b = $left['to'];
        $c = $right['from'];
        $d = $right['to'];
        $denominator = (($a[1] - $b[1]) * ($c[0] - $d[0])) - (($a[0] - $b[0]) * ($c[1] - $d[1]));
        if (abs($denominator) < 1e-14) {
            $connections = [];
            foreach ([[$a, 0.0], [$b, 1.0]] as [$point, $leftFraction]) {
                $rightFraction = $this->pointFractionOnSegment($point, $c, $d, $toleranceMeters);
                if ($rightFraction !== null) {
                    $connections[] = compact('point', 'leftFraction', 'rightFraction');
                }
            }
            foreach ([[$c, 0.0], [$d, 1.0]] as [$point, $rightFraction]) {
                $leftFraction = $this->pointFractionOnSegment($point, $a, $b, $toleranceMeters);
                if ($leftFraction !== null) {
                    $connections[] = compact('point', 'leftFraction', 'rightFraction');
                }
            }

            return array_values(array_map(fn (array $connection) => [
                'point' => $connection['point'],
                'left_fraction' => $connection['leftFraction'],
                'right_fraction' => $connection['rightFraction'],
            ], collect($connections)->unique(fn (array $connection) => sprintf(
                '%.9F,%.9F',
                $connection['point'][0],
                $connection['point'][1],
            ))->all()));
        }
        $leftFraction = ((($a[1] - $c[1]) * ($c[0] - $d[0])) - (($a[0] - $c[0]) * ($c[1] - $d[1]))) / $denominator;
        $rightFraction = -((($a[1] - $b[1]) * ($a[0] - $c[0])) - (($a[0] - $b[0]) * ($a[1] - $c[1]))) / $denominator;
        $epsilon = 1e-10;
        if ($leftFraction < -$epsilon || $leftFraction > 1 + $epsilon
            || $rightFraction < -$epsilon || $rightFraction > 1 + $epsilon) {
            return [];
        }
        $leftFraction = max(0.0, min(1.0, $leftFraction));

        return [[
            'point' => [
                $a[0] + (($b[0] - $a[0]) * $leftFraction),
                $a[1] + (($b[1] - $a[1]) * $leftFraction),
            ],
            'left_fraction' => $leftFraction,
            'right_fraction' => max(0.0, min(1.0, $rightFraction)),
        ]];
    }

    private function pointFractionOnSegment(array $point, array $start, array $end, float $toleranceMeters): ?float
    {
        if ($this->geometry->distanceToSegment($point[0], $point[1], $start, $end) > $toleranceMeters) {
            return null;
        }
        $dx = $end[1] - $start[1];
        $dy = $end[0] - $start[0];
        $lengthSquared = ($dx * $dx) + ($dy * $dy);
        if ($lengthSquared < 1e-20) {
            return null;
        }
        $fraction = ((($point[1] - $start[1]) * $dx) + (($point[0] - $start[0]) * $dy)) / $lengthSquared;

        return $fraction >= -1e-10 && $fraction <= 1 + 1e-10
            ? max(0.0, min(1.0, $fraction))
            : null;
    }

    /** Compare only segments whose small geographic bounding boxes share a grid cell. */
    private function intersectionCandidates(array $segments): array
    {
        $cellSize = 0.0002;
        $grid = [];
        $pairs = [];

        foreach ($segments as $index => $segment) {
            $minLat = (int) floor(min($segment['from'][0], $segment['to'][0]) / $cellSize);
            $maxLat = (int) floor(max($segment['from'][0], $segment['to'][0]) / $cellSize);
            $minLng = (int) floor(min($segment['from'][1], $segment['to'][1]) / $cellSize);
            $maxLng = (int) floor(max($segment['from'][1], $segment['to'][1]) / $cellSize);

            for ($lat = $minLat; $lat <= $maxLat; $lat++) {
                for ($lng = $minLng; $lng <= $maxLng; $lng++) {
                    $cell = $lat.':'.$lng;
                    foreach ($grid[$cell] ?? [] as $other) {
                        $pairs[$other.':'.$index] = [$other, $index];
                    }
                    $grid[$cell][] = $index;
                }
            }
        }

        return array_values($pairs);
    }
}

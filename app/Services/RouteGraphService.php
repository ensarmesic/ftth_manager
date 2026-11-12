<?php

namespace App\Services;

use App\Models\GisRestrictedArea;
use App\Models\GisSegment;
use App\Models\NetworkRoute;
use Illuminate\Support\Collection;
use SplPriorityQueue;

class RouteGraphService
{
    private const JOIN_TOLERANCE_M = 2.0;

    private const GRID_CELL_M = 50.0;

    private const MAX_CELLS_PER_PIECE = 4096;

    public function __construct(private readonly GeometryService $geometry) {}

    public function shortestPath(int $projectId, array $from, array $to, bool $preferSurveyedRoutes = false, bool $preferNetworkRouteAtDestination = false): ?array
    {
        $network = $this->network($projectId, $preferSurveyedRoutes, $preferNetworkRouteAtDestination);

        return $network ? $this->routesFrom($network, $from, ['to' => $to])['to'] : null;
    }

    /**
     * Load a project's routable lines and build the graph once. Pass the result
     * to routesFrom() to route many destinations without rebuilding it.
     */
    public function network(int $projectId, bool $preferSurveyedRoutes = false, bool $preferNetworkRouteAtDestination = false): ?array
    {
        $segments = collect();
        $source = $preferSurveyedRoutes ? 'routes' : 'gis';

        if (! $preferSurveyedRoutes) {
            $segments = GisSegment::query()
                ->where('project_id', $projectId)
                ->where('is_allowed', true)
                ->whereIn('segment_type', ['road', 'corridor', 'sidewalk'])
                ->get()
                ->filter(fn (GisSegment $segment) => count($segment->path ?? []) > 1)
                ->values();
        }

        if ($segments->isEmpty()) {
            $segments = NetworkRoute::query()
                ->where('project_id', $projectId)
                ->where('route_type', '!=', 'drop')
                ->whereNotNull('path')
                ->get()
                ->filter(fn (NetworkRoute $route) => count($route->path ?? []) > 1)
                ->values();
            $source = 'routes';
        }

        if ($segments->isEmpty()) {
            return null;
        }

        $restrictedAreas = $source === 'gis'
            ? GisRestrictedArea::query()
                ->where('project_id', $projectId)
                ->where('area_type', 'restricted')
                ->get()
                ->pluck('polygon')
                ->filter(fn ($polygon) => is_array($polygon) && count($polygon) >= 4)
                ->values()
                ->all()
            : [];

        return [
            'source' => $source,
            'segments' => $segments->count(),
            'restricted_areas' => count($restrictedAreas),
            'prefer_network_route_at_destination' => $preferNetworkRouteAtDestination,
            'graph' => $this->buildGraph($segments, $restrictedAreas, $preferNetworkRouteAtDestination),
        ];
    }

    /**
     * Shortest routes from one point to many targets over a prepared network.
     * One graph search serves every target. Returns [targetKey => route|null].
     */
    public function routesFrom(array $network, array $from, array $targets): array
    {
        $results = array_fill_keys(array_keys($targets), null);
        $graph = $network['graph'];
        $start = $this->attachPoint($graph, $from, '__start');
        if (! $start || $targets === []) {
            return $results;
        }

        $attached = [];
        $single = count($targets) === 1;
        foreach ($targets as $targetKey => $point) {
            $nodeKey = $single ? '__end' : '__end:'.$targetKey;
            $attachment = $this->attachPoint($graph, $point, $nodeKey, $network['prefer_network_route_at_destination'] ?? false);
            if ($attachment) {
                $attached[$targetKey] = $attachment;
            }
        }
        if ($attached === []) {
            return $results;
        }

        $previous = $this->dijkstra($graph['edges'], $start['key'], $single ? array_values($attached)[0]['key'] : null);
        $meta = [
            'source' => $network['source'],
            'nodes' => count($graph['nodes']),
            'edges' => array_sum(array_map('count', $graph['edges'])),
            'segments' => $network['segments'],
            'restricted_areas' => $network['restricted_areas'],
            'nearby_comparisons' => $graph['nearby_comparisons'],
        ];

        foreach ($attached as $targetKey => $attachment) {
            $keys = $this->pathKeys($previous, $start['key'], $attachment['key']);
            if (! $keys) {
                continue;
            }
            $path = array_map(fn (string $key) => $graph['nodes'][$key], $keys);
            $results[$targetKey] = [
                'path' => $this->geometry->compactPath($path),
                'length_m' => $this->geometry->polylineLength($path),
                'access_point' => $attachment['projection'],
                'access_distance_m' => $attachment['distance_m'],
                'graph' => $meta,
            ];
        }

        return $results;
    }

    private function buildGraph(Collection $routes, array $restrictedAreas = [], bool $preferNetworkRoutes = false): array
    {
        $nodes = [];
        $edges = [];
        $pieces = [];

        foreach ($routes as $route) {
            $path = $route->path ?? [];
            $isTrench = ($route->route_type ?? null) === 'trench';
            // The physical trench is the access corridor. Once a connected
            // distribution/feeder route exists, prefer following it to ODO.
            $factor = $preferNetworkRoutes && $isTrench ? 1.15 : 1.0;
            for ($i = 0; $i < count($path); $i++) {
                $key = $this->nodeKey($path[$i]);
                $nodes[$key] = $this->normalizedPoint($path[$i]);

                if ($i === 0) {
                    continue;
                }

                $prevKey = $this->nodeKey($path[$i - 1]);
                if ($prevKey === $key || $this->segmentBlockedByRestrictedArea($path[$i - 1], $path[$i], $restrictedAreas)) {
                    continue;
                }

                $this->connect($edges, $prevKey, $key, $this->geometry->distanceBetweenPoints($path[$i - 1], $path[$i]) * $factor);
                $pieces[] = [
                    'route_id' => $route->id,
                    'a' => $nodes[$prevKey],
                    'b' => $nodes[$key],
                    'a_key' => $prevKey,
                    'b_key' => $key,
                    'factor' => $factor,
                    'trench' => $isTrench,
                    'splits' => [],
                ];
            }
        }

        $vertexKeys = array_keys($nodes);
        $index = $this->pieceIndex($pieces, $nodes);
        $intersectionComparisons = $this->splitAtCrossings($pieces, $nodes, $index);
        $this->splitAtTouchingVertices($pieces, $nodes, $vertexKeys, $index);
        foreach ($pieces as $piece) {
            $this->chainPiece($piece, $nodes, $edges);
        }
        $nearbyComparisons = $this->connectNearbyNodes($nodes, $edges);

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'pieces' => $pieces,
            'nearby_comparisons' => $nearbyComparisons + $intersectionComparisons,
        ];
    }

    private function connect(array &$edges, string $a, string $b, float $weight): void
    {
        if ($a === $b) {
            return;
        }
        $edges[$a][$b] = min($edges[$a][$b] ?? INF, $weight);
        $edges[$b][$a] = min($edges[$b][$a] ?? INF, $weight);
    }

    /**
     * Spatial grid over the line pieces, so crossings and touching vertices are
     * found by looking at neighbours instead of comparing every pair.
     */
    private function pieceIndex(array $pieces, array $nodes): array
    {
        $referenceLatitude = $nodes === [] ? 0.0 : array_sum(array_column($nodes, 0)) / count($nodes);
        $latCell = self::GRID_CELL_M / 111320;
        $lngCell = self::GRID_CELL_M / (111320 * max(cos(deg2rad($referenceLatitude)), 0.00001));
        $latPad = self::JOIN_TOLERANCE_M / 111320;
        $lngPad = $latPad * ($lngCell / $latCell);
        $cells = [];
        $oversized = [];

        foreach ($pieces as $i => $piece) {
            $minY = (int) floor((min($piece['a'][0], $piece['b'][0]) - $latPad) / $latCell);
            $maxY = (int) floor((max($piece['a'][0], $piece['b'][0]) + $latPad) / $latCell);
            $minX = (int) floor((min($piece['a'][1], $piece['b'][1]) - $lngPad) / $lngCell);
            $maxX = (int) floor((max($piece['a'][1], $piece['b'][1]) + $lngPad) / $lngCell);
            if (($maxX - $minX + 1) * ($maxY - $minY + 1) > self::MAX_CELLS_PER_PIECE) {
                $oversized[] = $i;

                continue;
            }
            for ($x = $minX; $x <= $maxX; $x++) {
                for ($y = $minY; $y <= $maxY; $y++) {
                    $cells[$x.':'.$y][] = $i;
                }
            }
        }

        return ['cells' => $cells, 'oversized' => $oversized, 'lat_cell' => $latCell, 'lng_cell' => $lngCell];
    }

    private function cellKey(array $index, float $lat, float $lng): string
    {
        return ((int) floor($lng / $index['lng_cell'])).':'.((int) floor($lat / $index['lat_cell']));
    }

    /**
     * Where two lines cross without a shared vertex, add the crossing as a
     * split point on both of them.
     */
    private function splitAtCrossings(array &$pieces, array &$nodes, array $index): int
    {
        $comparisons = 0;
        $compare = function (int $i, int $j, ?string $cell) use (&$pieces, &$nodes, &$comparisons, $index): void {
            $first = $pieces[$i];
            $second = $pieces[$j];
            if ($first['route_id'] === $second['route_id'] || ! $this->segmentBoundsOverlap($first, $second)) {
                return;
            }
            if ($cell !== null) {
                // A pair can share several cells; handle it only in the cell
                // holding the corner of the area where their bounds overlap.
                $lat = max(min($first['a'][0], $first['b'][0]), min($second['a'][0], $second['b'][0]));
                $lng = max(min($first['a'][1], $first['b'][1]), min($second['a'][1], $second['b'][1]));
                if ($this->cellKey($index, $lat, $lng) !== $cell) {
                    return;
                }
            }
            $comparisons++;
            $intersection = $this->segmentIntersection($first['a'], $first['b'], $second['a'], $second['b']);
            if (! $intersection) {
                return;
            }
            $key = $this->nodeKey($intersection);
            $nodes[$key] ??= $this->normalizedPoint($intersection);
            foreach ([$i, $j] as $pieceIndex) {
                $this->addSplit($pieces[$pieceIndex], $key, $nodes[$key]);
            }
        };

        foreach ($index['cells'] as $cell => $members) {
            $count = count($members);
            for ($m = 0; $m < $count; $m++) {
                for ($n = $m + 1; $n < $count; $n++) {
                    $compare($members[$m], $members[$n], (string) $cell);
                }
            }
        }
        $oversized = array_flip($index['oversized']);
        foreach ($index['oversized'] as $i) {
            foreach (array_keys($pieces) as $j) {
                if ($i !== $j && ! (isset($oversized[$j]) && $j < $i)) {
                    $compare($i, $j, null);
                }
            }
        }

        return $comparisons;
    }

    /**
     * Join a line vertex that lands on the middle of another line. Drawn
     * branches commonly end on an existing line without adding a vertex to
     * it, so comparing vertices alone leaves a false gap.
     */
    private function splitAtTouchingVertices(array &$pieces, array $nodes, array $vertexKeys, array $index): void
    {
        foreach ($vertexKeys as $key) {
            $key = (string) $key;
            $point = $nodes[$key];
            $candidates = array_merge($index['cells'][$this->cellKey($index, $point[0], $point[1])] ?? [], $index['oversized']);
            foreach ($candidates as $i) {
                $piece = $pieces[$i];
                if ($key === $piece['a_key'] || $key === $piece['b_key']) {
                    continue;
                }
                $projection = $this->projectOnPiece($point, $piece['a'], $piece['b']);
                if ($projection['distance_m'] <= self::JOIN_TOLERANCE_M && $projection['t'] > 0.0 && $projection['t'] < 1.0) {
                    $this->addSplit($pieces[$i], $key, $point, $projection['t']);
                }
            }
        }
    }

    private function addSplit(array &$piece, string $key, array $point, ?float $t = null): void
    {
        if ($key === $piece['a_key'] || $key === $piece['b_key'] || isset($piece['splits'][$key])) {
            return;
        }
        $piece['splits'][$key] = $t ?? $this->parameterOn($point, $piece['a'], $piece['b']);
    }

    /**
     * Position of a point along a piece: 0 at its start, 1 at its end.
     */
    private function parameterOn(array $point, array $a, array $b): float
    {
        $dy = $b[0] - $a[0];
        $dx = $b[1] - $a[1];
        $length = ($dx ** 2) + ($dy ** 2);

        return $length <= 0.0 ? 0.0 : max(0.0, min(1.0, ((($point[1] - $a[1]) * $dx) + (($point[0] - $a[0]) * $dy)) / $length));
    }

    /**
     * Link a piece's split points in order along it, so a route can move from
     * one to the next without detouring through the piece's ends.
     */
    private function chainPiece(array $piece, array $nodes, array &$edges): void
    {
        if ($piece['splits'] === []) {
            return;
        }
        asort($piece['splits']);
        $previous = $piece['a_key'];
        foreach ([...array_keys($piece['splits']), $piece['b_key']] as $key) {
            $key = (string) $key;
            $this->connect($edges, $previous, $key, $this->geometry->distanceBetweenPoints($nodes[$previous], $nodes[$key]) * $piece['factor']);
            $previous = $key;
        }
    }

    private function segmentBoundsOverlap(array $first, array $second): bool
    {
        return min($first['a'][0], $first['b'][0]) <= max($second['a'][0], $second['b'][0])
            && max($first['a'][0], $first['b'][0]) >= min($second['a'][0], $second['b'][0])
            && min($first['a'][1], $first['b'][1]) <= max($second['a'][1], $second['b'][1])
            && max($first['a'][1], $first['b'][1]) >= min($second['a'][1], $second['b'][1]);
    }

    private function segmentIntersection(array $a, array $b, array $c, array $d): ?array
    {
        $denominator = (($a[1] - $b[1]) * ($c[0] - $d[0])) - (($a[0] - $b[0]) * ($c[1] - $d[1]));
        if (abs($denominator) < 1e-14) {
            return null;
        }
        $firstDeterminant = ($a[1] * $b[0]) - ($a[0] * $b[1]);
        $secondDeterminant = ($c[1] * $d[0]) - ($c[0] * $d[1]);
        $lng = (($firstDeterminant * ($c[1] - $d[1])) - (($a[1] - $b[1]) * $secondDeterminant)) / $denominator;
        $lat = (($firstDeterminant * ($c[0] - $d[0])) - (($a[0] - $b[0]) * $secondDeterminant)) / $denominator;
        $point = [$lat, $lng];

        return $this->pointWithinSegment($point, $a, $b) && $this->pointWithinSegment($point, $c, $d) ? $point : null;
    }

    private function pointWithinSegment(array $point, array $a, array $b): bool
    {
        $epsilon = 1e-9;

        return $point[0] >= min($a[0], $b[0]) - $epsilon && $point[0] <= max($a[0], $b[0]) + $epsilon
            && $point[1] >= min($a[1], $b[1]) - $epsilon && $point[1] <= max($a[1], $b[1]) + $epsilon;
    }

    private function connectNearbyNodes(array $nodes, array &$edges, float $toleranceMeters = self::JOIN_TOLERANCE_M): int
    {
        if (count($nodes) < 2 || $toleranceMeters <= 0) {
            return 0;
        }

        $referenceLatitude = array_sum(array_column($nodes, 0)) / count($nodes);
        $longitudeScale = 111320 * max(cos(deg2rad($referenceLatitude)), 0.00001);
        $grid = [];
        $comparisons = 0;

        foreach ($nodes as $aKey => $point) {
            $cellX = (int) floor(((float) $point[1] * $longitudeScale) / $toleranceMeters);
            $cellY = (int) floor(((float) $point[0] * 111320) / $toleranceMeters);

            for ($x = $cellX - 1; $x <= $cellX + 1; $x++) {
                for ($y = $cellY - 1; $y <= $cellY + 1; $y++) {
                    foreach ($grid[$x.':'.$y] ?? [] as $bKey) {
                        $comparisons++;
                        $distance = $this->geometry->distanceBetweenPoints($nodes[$aKey], $nodes[$bKey]);
                        if ($distance > 0 && $distance <= $toleranceMeters) {
                            $edges[$aKey][$bKey] = min($edges[$aKey][$bKey] ?? INF, $distance);
                            $edges[$bKey][$aKey] = min($edges[$bKey][$aKey] ?? INF, $distance);
                        }
                    }
                }
            }

            $grid[$cellX.':'.$cellY][] = $aKey;
        }

        return $comparisons;
    }

    /**
     * Hang a free point (ODF, house, cabinet) on the nearest line piece and
     * link its projection to the closest split points on either side.
     *
     * @return array{key:string,projection:array,distance_m:float}|null
     */
    private function attachPoint(array &$graph, array $point, string $key, bool $avoidTrench = false): ?array
    {
        $nearest = null;
        foreach ([$avoidTrench, false] as $skipTrench) {
            foreach ($graph['pieces'] as $i => $piece) {
                if ($skipTrench && $piece['trench']) {
                    continue;
                }
                $projection = $this->projectOnPiece($point, $piece['a'], $piece['b']);
                if (! $nearest || $projection['distance_m'] < $nearest['distance_m']) {
                    $nearest = $projection + ['piece' => $i];
                }
            }
            if ($nearest || ! $skipTrench) {
                break;
            }
        }

        if (! $nearest) {
            return null;
        }

        $piece = $graph['pieces'][$nearest['piece']];
        $original = [(float) $point[0], (float) $point[1]];
        $projected = [(float) $nearest['lat'], (float) $nearest['lng']];
        $projectionKey = $key.'__projection';
        $graph['nodes'][$key] = $original;
        $graph['nodes'][$projectionKey] = $projected;
        $this->connect($graph['edges'], $key, $projectionKey, $nearest['distance_m']);

        $before = ['key' => $piece['a_key'], 't' => 0.0];
        $after = ['key' => $piece['b_key'], 't' => 1.0];
        foreach ($piece['splits'] as $splitKey => $t) {
            if ($t <= $nearest['t'] && $t >= $before['t']) {
                $before = ['key' => (string) $splitKey, 't' => $t];
            }
            if ($t >= $nearest['t'] && $t <= $after['t']) {
                $after = ['key' => (string) $splitKey, 't' => $t];
            }
        }
        foreach ([$before['key'], $after['key']] as $neighbour) {
            $weight = $this->geometry->distanceBetweenPoints($projected, $graph['nodes'][$neighbour]) * $piece['factor'];
            $this->connect($graph['edges'], $projectionKey, $neighbour, $weight);
        }
        $graph['pieces'][$nearest['piece']]['splits'][$projectionKey] = $nearest['t'];

        return ['key' => $key, 'projection' => $projected, 'distance_m' => (float) $nearest['distance_m']];
    }

    /**
     * @return array{t:float,lat:float,lng:float,distance_m:float}
     */
    private function projectOnPiece(array $point, array $a, array $b): array
    {
        $lat = (float) $point[0];
        $lng = (float) $point[1];
        $start = $this->geometry->toCartesian((float) $a[0], (float) $a[1], $lat, $lng);
        $end = $this->geometry->toCartesian((float) $b[0], (float) $b[1], $lat, $lng);
        $dx = $end['x'] - $start['x'];
        $dy = $end['y'] - $start['y'];
        $t = max(0.0, min(1.0, ((-$start['x'] * $dx) + (-$start['y'] * $dy)) / max(0.000001, ($dx ** 2) + ($dy ** 2))));
        $x = $start['x'] + ($dx * $t);
        $y = $start['y'] + ($dy * $t);
        $projected = match (true) {
            $t <= 0.0 => [(float) $a[0], (float) $a[1]],
            $t >= 1.0 => [(float) $b[0], (float) $b[1]],
            default => [$lat + ($y / 111320), $lng + ($x / (111320 * cos(deg2rad($lat))))],
        };

        return ['t' => $t, 'lat' => $projected[0], 'lng' => $projected[1], 'distance_m' => sqrt(($x ** 2) + ($y ** 2))];
    }

    private function segmentBlockedByRestrictedArea(array $a, array $b, array $restrictedAreas): bool
    {
        if (! count($restrictedAreas)) {
            return false;
        }

        foreach ($restrictedAreas as $polygon) {
            if ($this->pointInPolygon($a, $polygon) || $this->pointInPolygon($b, $polygon)) {
                return true;
            }

            $midpoint = [((float) $a[0] + (float) $b[0]) / 2, ((float) $a[1] + (float) $b[1]) / 2];
            if ($this->pointInPolygon($midpoint, $polygon)) {
                return true;
            }

            for ($i = 1; $i < count($polygon); $i++) {
                if ($this->segmentsIntersect($a, $b, $polygon[$i - 1], $polygon[$i])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pointInPolygon(array $point, array $polygon): bool
    {
        $inside = false;
        $x = (float) $point[1];
        $y = (float) $point[0];
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $polygon[$i][1];
            $yi = (float) $polygon[$i][0];
            $xj = (float) $polygon[$j][1];
            $yj = (float) $polygon[$j][0];

            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 0.000000001)) + $xi);
            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        $a = ['x' => (float) $a[1], 'y' => (float) $a[0]];
        $b = ['x' => (float) $b[1], 'y' => (float) $b[0]];
        $c = ['x' => (float) $c[1], 'y' => (float) $c[0]];
        $d = ['x' => (float) $d[1], 'y' => (float) $d[0]];

        $o1 = $this->orientation($a, $b, $c);
        $o2 = $this->orientation($a, $b, $d);
        $o3 = $this->orientation($c, $d, $a);
        $o4 = $this->orientation($c, $d, $b);

        if ($o1 !== $o2 && $o3 !== $o4) {
            return true;
        }

        return ($o1 === 0 && $this->onSegment($a, $c, $b))
            || ($o2 === 0 && $this->onSegment($a, $d, $b))
            || ($o3 === 0 && $this->onSegment($c, $a, $d))
            || ($o4 === 0 && $this->onSegment($c, $b, $d));
    }

    private function orientation(array $a, array $b, array $c): int
    {
        $value = (($b['y'] - $a['y']) * ($c['x'] - $b['x'])) - (($b['x'] - $a['x']) * ($c['y'] - $b['y']));
        if (abs($value) < 0.000000000001) {
            return 0;
        }

        return $value > 0 ? 1 : 2;
    }

    private function onSegment(array $a, array $b, array $c): bool
    {
        return $b['x'] <= max($a['x'], $c['x']) && $b['x'] >= min($a['x'], $c['x'])
            && $b['y'] <= max($a['y'], $c['y']) && $b['y'] >= min($a['y'], $c['y']);
    }

    /**
     * Shortest-path tree from $start as [node => previous node]. With $end the
     * search stops as soon as that node is settled.
     */
    private function dijkstra(array $edges, string $start, ?string $end = null): array
    {
        $dist = [$start => 0.0];
        $prev = [];
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $queue->insert($start, 0.0);

        while (! $queue->isEmpty()) {
            $entry = $queue->extract();
            $current = (string) $entry['data'];
            $currentDistance = -(float) $entry['priority'];
            if ($currentDistance > ($dist[$current] ?? INF)) {
                continue;
            }

            if ($current === $end) {
                break;
            }

            foreach (($edges[$current] ?? []) as $next => $weight) {
                $candidate = $dist[$current] + $weight;
                if ($candidate < ($dist[$next] ?? INF)) {
                    $dist[$next] = $candidate;
                    $prev[$next] = $current;
                    $queue->insert($next, -$candidate);
                }
            }
        }

        return $prev;
    }

    private function pathKeys(array $previous, string $start, string $end): ?array
    {
        $path = [];
        for ($node = $end; $node !== null; $node = $previous[$node] ?? null) {
            $path[] = (string) $node;
            if ($node === $start) {
                return array_reverse($path);
            }
        }

        return null;
    }

    private function nodeKey(array $point): string
    {
        return round((float) $point[0], 7).','.round((float) $point[1], 7);
    }

    private function normalizedPoint(array $point): array
    {
        return [round((float) $point[0], 7), round((float) $point[1], 7)];
    }
}

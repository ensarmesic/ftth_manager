<?php

namespace App\Services;

use App\Models\GisRestrictedArea;
use App\Models\GisSegment;
use App\Models\NetworkRoute;
use Illuminate\Support\Collection;
use SplPriorityQueue;

class RouteGraphService
{
    public function __construct(private readonly GeometryService $geometry) {}

    public function shortestPath(int $projectId, array $from, array $to, bool $preferSurveyedRoutes = false): ?array
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

        $graph = $this->buildGraph($segments, $restrictedAreas);
        $start = $this->attachPoint($graph, $segments, $from, '__start', $restrictedAreas);
        $end = $this->attachPoint($graph, $segments, $to, '__end', $restrictedAreas);

        if (! $start || ! $end) {
            return null;
        }

        $keys = $this->dijkstra($graph['edges'], $start, $end);
        if (! $keys) {
            return null;
        }

        $path = array_map(fn (string $key) => $graph['nodes'][$key], $keys);

        return [
            'path' => $this->geometry->compactPath($path),
            'length_m' => $this->geometry->polylineLength($path),
            'graph' => [
                'source' => $source,
                'nodes' => count($graph['nodes']),
                'edges' => array_sum(array_map('count', $graph['edges'])),
                'segments' => $segments->count(),
                'restricted_areas' => count($restrictedAreas),
                'nearby_comparisons' => $graph['nearby_comparisons'],
            ],
        ];
    }

    private function buildGraph(Collection $routes, array $restrictedAreas = []): array
    {
        $nodes = [];
        $edges = [];

        foreach ($routes as $route) {
            $path = $route->path ?? [];
            for ($i = 0; $i < count($path); $i++) {
                $key = $this->nodeKey($path[$i]);
                $nodes[$key] = $this->normalizedPoint($path[$i]);

                if ($i === 0) {
                    continue;
                }

                $prevKey = $this->nodeKey($path[$i - 1]);
                if ($this->segmentBlockedByRestrictedArea($path[$i - 1], $path[$i], $restrictedAreas)) {
                    continue;
                }

                $weight = $this->geometry->distanceBetweenPoints($path[$i - 1], $path[$i]);
                $edges[$prevKey][$key] = min($edges[$prevKey][$key] ?? INF, $weight);
                $edges[$key][$prevKey] = min($edges[$key][$prevKey] ?? INF, $weight);
            }
        }

        $nearbyComparisons = $this->connectNearbyNodes($nodes, $edges);

        return ['nodes' => $nodes, 'edges' => $edges, 'nearby_comparisons' => $nearbyComparisons];
    }

    private function connectNearbyNodes(array $nodes, array &$edges, float $toleranceMeters = 2.0): int
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

    private function attachPoint(array &$graph, Collection $routes, array $point, string $key, array $restrictedAreas = []): ?string
    {
        $nearest = null;

        foreach ($routes as $route) {
            $projection = $this->projectPointToPath((float) $point[0], (float) $point[1], $route->path ?? []);
            $path = $route->path ?? [];
            $segmentIndex = (int) ($projection['segment_index'] ?? 0);
            $a = $path[$segmentIndex - 1] ?? null;
            $b = $path[$segmentIndex] ?? null;
            if (! $a || ! $b || $this->segmentBlockedByRestrictedArea($a, $b, $restrictedAreas)) {
                continue;
            }

            if (! $nearest || $projection['distance_m'] < $nearest['distance_m']) {
                $nearest = $projection + ['route' => $route];
            }
        }

        if (! $nearest || ! isset($nearest['segment_index'])) {
            return null;
        }

        $path = $nearest['route']->path ?? [];
        $segmentIndex = (int) $nearest['segment_index'];
        $a = $path[$segmentIndex - 1] ?? null;
        $b = $path[$segmentIndex] ?? null;

        if (! $a || ! $b) {
            return null;
        }

        $original = [(float) $point[0], (float) $point[1]];
        $projected = [(float) $nearest['lat'], (float) $nearest['lng']];
        $projectionKey = $key.'__projection';
        $graph['nodes'][$key] = $original;
        $graph['nodes'][$projectionKey] = $projected;

        $spurWeight = $this->geometry->distanceBetweenPoints($original, $projected);
        $graph['edges'][$key][$projectionKey] = $spurWeight;
        $graph['edges'][$projectionKey][$key] = $spurWeight;

        foreach ([$a, $b] as $endpoint) {
            $endpointKey = $this->nodeKey($endpoint);
            $weight = $this->geometry->distanceBetweenPoints($projected, $endpoint);
            $graph['edges'][$projectionKey][$endpointKey] = $weight;
            $graph['edges'][$endpointKey][$projectionKey] = $weight;
        }

        return $key;
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

    private function projectPointToPath(float $lat, float $lng, array $points): array
    {
        $best = null;
        $chainage = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $a = $this->geometry->toCartesian((float) $points[$i - 1][0], (float) $points[$i - 1][1], $lat, $lng);
            $b = $this->geometry->toCartesian((float) $points[$i][0], (float) $points[$i][1], $lat, $lng);
            $abx = $b['x'] - $a['x'];
            $aby = $b['y'] - $a['y'];
            $ab2 = max(0.000001, ($abx ** 2) + ($aby ** 2));
            $t = max(0, min(1, ((-$a['x'] * $abx) + (-$a['y'] * $aby)) / $ab2));
            $x = $a['x'] + ($abx * $t);
            $y = $a['y'] + ($aby * $t);
            $distance = sqrt(($x ** 2) + ($y ** 2));
            $segmentLength = sqrt($ab2);

            if (! $best || $distance < $best['distance_m']) {
                $best = [
                    'lat' => $lat + ($y / 111320),
                    'lng' => $lng + ($x / (111320 * cos(deg2rad($lat)))),
                    'distance_m' => $distance,
                    'chainage_m' => $chainage + ($segmentLength * $t),
                    'segment_index' => $i,
                ];
            }
            $chainage += $segmentLength;
        }

        return $best ?? ['lat' => $lat, 'lng' => $lng, 'distance_m' => INF, 'chainage_m' => 0, 'segment_index' => 0];
    }

    private function dijkstra(array $edges, string $start, string $end): ?array
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

        if (! isset($dist[$end])) {
            return null;
        }

        $path = [];
        for ($node = $end; $node !== null; $node = $prev[$node] ?? null) {
            array_unshift($path, $node);
            if ($node === $start) {
                return $path;
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

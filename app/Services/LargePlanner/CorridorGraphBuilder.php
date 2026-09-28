<?php

namespace App\Services\LargePlanner;

use App\Models\GisSegment;
use App\Models\Project;
use App\Services\GeometryService;

class CorridorGraphBuilder
{
    private const NODE_MERGE_TOLERANCE_M = 2.0;

    public function __construct(private readonly GeometryService $geometry) {}

    public function build(Project $project): array
    {
        $restrictedAreas = $project->largePlannerConstraints()
            ->where('type', 'restricted_area')
            ->pluck('geometry')
            ->filter(fn ($polygon) => is_array($polygon) && count($polygon) >= 3)
            ->values()
            ->all();
        $corridors = GisSegment::query()
            ->where('project_id', $project->id)
            ->where('is_allowed', true)
            ->whereNotNull('planning_corridor_type')
            ->orderBy('id')
            ->get()
            ->filter(fn (GisSegment $segment) => count($segment->path ?? []) >= 2)
            ->values();

        $nodes = [];
        $edges = [];
        $segmentNodes = [];
        $blockedEdges = 0;
        foreach ($corridors as $corridor) {
            $previousKey = null;
            foreach ($corridor->path as $point) {
                $normalized = [(float) $point[0], (float) $point[1]];
                $key = $this->nodeKey($normalized);
                $nodes[$key] ??= $normalized;
                $segmentNodes[$corridor->id][] = $key;
                if ($previousKey !== null && $previousKey !== $key) {
                    if ($this->segmentBlockedByRestrictedArea($nodes[$previousKey], $normalized, $restrictedAreas)) {
                        $blockedEdges++;
                    } else {
                        $weight = $this->geometry->distanceBetweenPoints($nodes[$previousKey], $normalized);
                        $this->connect($edges, $previousKey, $key, $weight, $corridor);
                    }
                }
                $previousKey = $key;
            }
        }

        $nearbyConnections = $this->connectNearbyNodes($nodes, $edges);
        $components = $this->components($nodes, $edges);

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'segment_nodes' => $segmentNodes,
            'components' => $components,
            'summary' => [
                'corridors' => $corridors->count(),
                'nodes' => count($nodes),
                'edges' => (int) (array_sum(array_map('count', $edges)) / 2),
                'components' => count($components),
                'nearby_connections' => $nearbyConnections,
                'blocked_edges' => $blockedEdges,
            ],
        ];
    }

    private function segmentBlockedByRestrictedArea(array $a, array $b, array $restrictedAreas): bool
    {
        foreach ($restrictedAreas as $polygon) {
            if ($this->pointInPolygon($a, $polygon) || $this->pointInPolygon($b, $polygon)) {
                return true;
            }

            $midpoint = [((float) $a[0] + (float) $b[0]) / 2, ((float) $a[1] + (float) $b[1]) / 2];
            if ($this->pointInPolygon($midpoint, $polygon)) {
                return true;
            }

            for ($index = 0; $index < count($polygon); $index++) {
                if ($this->segmentsIntersect($a, $b, $polygon[$index], $polygon[($index + 1) % count($polygon)])) {
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
        for ($index = 0, $previous = count($polygon) - 1; $index < count($polygon); $previous = $index++) {
            $xi = (float) $polygon[$index][1];
            $yi = (float) $polygon[$index][0];
            $xj = (float) $polygon[$previous][1];
            $yj = (float) $polygon[$previous][0];
            if ((($yi > $y) !== ($yj > $y)) && $x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 0.000000001)) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        $orientation = static function (array $p, array $q, array $r): int {
            $value = (($q[1] - $p[1]) * ($r[0] - $q[0])) - (($q[0] - $p[0]) * ($r[1] - $q[1]));

            return abs($value) < 0.000000000001 ? 0 : ($value > 0 ? 1 : 2);
        };
        $onSegment = static fn (array $p, array $q, array $r): bool => $q[0] <= max($p[0], $r[0]) && $q[0] >= min($p[0], $r[0])
            && $q[1] <= max($p[1], $r[1]) && $q[1] >= min($p[1], $r[1]);
        $o1 = $orientation($a, $b, $c);
        $o2 = $orientation($a, $b, $d);
        $o3 = $orientation($c, $d, $a);
        $o4 = $orientation($c, $d, $b);

        return ($o1 !== $o2 && $o3 !== $o4)
            || ($o1 === 0 && $onSegment($a, $c, $b))
            || ($o2 === 0 && $onSegment($a, $d, $b))
            || ($o3 === 0 && $onSegment($c, $a, $d))
            || ($o4 === 0 && $onSegment($c, $b, $d));
    }

    private function connect(array &$edges, string $from, string $to, float $weight, ?GisSegment $corridor = null): void
    {
        $edge = [
            'weight_m' => $weight,
            'corridor_id' => $corridor?->id,
            'corridor_type' => $corridor?->planning_corridor_type,
        ];
        if (! isset($edges[$from][$to]) || $weight < $edges[$from][$to]['weight_m']) {
            $edges[$from][$to] = $edge;
            $edges[$to][$from] = $edge;
        }
    }

    private function connectNearbyNodes(array $nodes, array &$edges): int
    {
        if (count($nodes) < 2) {
            return 0;
        }
        $referenceLatitude = array_sum(array_column($nodes, 0)) / count($nodes);
        $longitudeScale = 111320 * max(cos(deg2rad($referenceLatitude)), 0.00001);
        $grid = [];
        $created = 0;

        foreach ($nodes as $key => $point) {
            $cellX = (int) floor(($point[1] * $longitudeScale) / self::NODE_MERGE_TOLERANCE_M);
            $cellY = (int) floor(($point[0] * 111320) / self::NODE_MERGE_TOLERANCE_M);
            for ($x = $cellX - 1; $x <= $cellX + 1; $x++) {
                for ($y = $cellY - 1; $y <= $cellY + 1; $y++) {
                    foreach ($grid[$x.':'.$y] ?? [] as $otherKey) {
                        $distance = $this->geometry->distanceBetweenPoints($point, $nodes[$otherKey]);
                        if ($distance > 0 && $distance <= self::NODE_MERGE_TOLERANCE_M && ! isset($edges[$key][$otherKey])) {
                            $this->connect($edges, $key, $otherKey, $distance);
                            $created++;
                        }
                    }
                }
            }
            $grid[$cellX.':'.$cellY][] = $key;
        }

        return $created;
    }

    private function components(array $nodes, array $edges): array
    {
        $visited = [];
        $components = [];
        foreach (array_keys($nodes) as $start) {
            if (isset($visited[$start])) {
                continue;
            }
            $queue = [$start];
            $visited[$start] = true;
            $component = [];
            while ($queue !== []) {
                $node = array_shift($queue);
                $component[] = $node;
                foreach (array_keys($edges[$node] ?? []) as $next) {
                    if (! isset($visited[$next])) {
                        $visited[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
            sort($component);
            $components[] = $component;
        }

        return $components;
    }

    private function nodeKey(array $point): string
    {
        return number_format($point[0], 7, '.', '').','.number_format($point[1], 7, '.', '');
    }
}

<?php

namespace App\Services;

use App\Models\NetworkRoute;

class GeometryService
{
    /**
     * Convert lat/lng to local Cartesian metres relative to an origin point.
     */
    public function toCartesian(float $lat, float $lng, float $originLat, float $originLng): array
    {
        return [
            'x' => ($lng - $originLng) * 111320 * cos(deg2rad($originLat)),
            'y' => ($lat - $originLat) * 111320,
        ];
    }

    /**
     * Haversine distance between two points given as [lat, lng] arrays.
     */
    public function distanceBetweenPoints(array $a, array $b): float
    {
        return $this->distanceMeters((float) $a[0], (float) $a[1], (float) $b[0], (float) $b[1]);
    }

    /**
     * Haversine distance in metres between two scalar lat/lng pairs.
     */
    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Total haversine length of a polyline given as [[lat, lng], …].
     */
    public function polylineLength(array $points): int
    {
        $distance = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $distance += $this->distanceBetweenPoints($points[$i - 1], $points[$i]);
        }

        return (int) round($distance);
    }

    /**
     * Shortest distance (metres) from a point to a line segment.
     */
    public function distanceToSegment(float $lat, float $lng, array $start, array $end): float
    {
        $a = $this->toCartesian((float) $start[0], (float) $start[1], $lat, $lng);
        $b = $this->toCartesian((float) $end[0], (float) $end[1], $lat, $lng);
        $p = ['x' => 0.0, 'y' => 0.0];

        $abx = $b['x'] - $a['x'];
        $aby = $b['y'] - $a['y'];
        $ab2 = max(0.000001, ($abx ** 2) + ($aby ** 2));
        $t = max(0, min(1, ((($p['x'] - $a['x']) * $abx) + (($p['y'] - $a['y']) * $aby)) / $ab2));
        $x = $a['x'] + ($abx * $t);
        $y = $a['y'] + ($aby * $t);

        return sqrt((($p['x'] - $x) ** 2) + (($p['y'] - $y) ** 2));
    }

    /**
     * Shortest distance (metres) from a point to any segment of a polyline.
     */
    public function distanceToRoute(float $lat, float $lng, array $points): float
    {
        $best = INF;
        for ($i = 1; $i < count($points); $i++) {
            $best = min($best, $this->distanceToSegment($lat, $lng, $points[$i - 1], $points[$i]));
        }

        return $best;
    }

    /**
     * Project a point onto a NetworkRoute geometry.
     * Returns [lat, lng, distance_m, chainage_m, segment_index].
     */
    public function projectPointToRoute(float $lat, float $lng, NetworkRoute $route): array
    {
        $points = $route->path ?? [];
        $best = null;
        $chainage = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $a = $this->toCartesian((float) $points[$i - 1][0], (float) $points[$i - 1][1], $lat, $lng);
            $b = $this->toCartesian((float) $points[$i][0], (float) $points[$i][1], $lat, $lng);
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

    /**
     * Slice a route's path between two projection points (from projectPointToRoute).
     */
    public function routePathBetween(NetworkRoute $route, array $start, array $end): array
    {
        $points = array_values($route->path ?? []);
        if (count($points) < 2) {
            return [[$start['lat'], $start['lng']], [$end['lat'], $end['lng']]];
        }

        $chainages = [0.0];
        for ($i = 1; $i < count($points); $i++) {
            $chainages[$i] = $chainages[$i - 1] + $this->distanceBetweenPoints($points[$i - 1], $points[$i]);
        }

        $reverse = $start['chainage_m'] > $end['chainage_m'];
        $from = $reverse ? $end : $start;
        $to = $reverse ? $start : $end;

        $path = [[$from['lat'], $from['lng']]];
        for ($i = max(1, (int) $from['segment_index']); $i < count($points); $i++) {
            if ($chainages[$i] <= $from['chainage_m'] + 0.01) {
                continue;
            }
            if ($chainages[$i] >= $to['chainage_m'] - 0.01) {
                break;
            }
            $path[] = [(float) $points[$i][0], (float) $points[$i][1]];
        }
        $path[] = [$to['lat'], $to['lng']];

        return $reverse ? array_reverse($this->compactPath($path)) : $this->compactPath($path);
    }

    /**
     * Remove consecutive duplicate points from a path.
     */
    public function compactPath(array $path): array
    {
        $compact = [];
        foreach ($path as $point) {
            $normalized = [round((float) $point[0], 7), round((float) $point[1], 7)];
            $last = $compact[array_key_last($compact)] ?? null;
            if (! $last || abs($last[0] - $normalized[0]) > 0.0000001 || abs($last[1] - $normalized[1]) > 0.0000001) {
                $compact[] = $normalized;
            }
        }

        return $compact;
    }
}

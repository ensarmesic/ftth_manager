<?php

namespace App\Services\PlannerLab;

class PlannerGeometry
{
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function pathLength(array $path): float
    {
        $total = 0;
        for ($i = 0; $i < count($path) - 1; $i++) {
            $total += self::haversine($path[$i][0], $path[$i][1], $path[$i + 1][0], $path[$i + 1][1]);
        }
        return round($total, 1);
    }

    public static function centroid(array $points): array
    {
        $lat = array_sum(array_column($points, 'latitude')) / count($points);
        $lng = array_sum(array_column($points, 'longitude')) / count($points);
        return ['lat' => $lat, 'lng' => $lng];
    }

    public static function medoid(array $points): array
    {
        $best = null;
        $bestDist = PHP_FLOAT_MAX;
        foreach ($points as $candidate) {
            $total = 0;
            foreach ($points as $other) {
                $total += self::haversine($candidate['latitude'], $candidate['longitude'], $other['latitude'], $other['longitude']);
            }
            if ($total < $bestDist) {
                $bestDist = $total;
                $best = $candidate;
            }
        }
        return ['lat' => $best['latitude'], 'lng' => $best['longitude']];
    }

    /**
     * Project a lat/lng point onto a plain [[lat,lng],...] polyline.
     * Returns [lat, lng, distance_m, chainage_m, segment_index].
     * Mirrors GeometryService::projectPointToRoute() but works with raw arrays.
     */
    public static function projectPointToPath(float $lat, float $lng, array $path): array
    {
        $best     = null;
        $chainage = 0.0;
        $latScale = 111320.0;
        $lngScale = 111320.0 * cos(deg2rad($lat));

        for ($i = 1; $i < count($path); $i++) {
            $ax = ((float) $path[$i - 1][1] - $lng) * $lngScale;
            $ay = ((float) $path[$i - 1][0] - $lat) * $latScale;
            $bx = ((float) $path[$i][1]     - $lng) * $lngScale;
            $by = ((float) $path[$i][0]     - $lat) * $latScale;

            $abx = $bx - $ax;
            $aby = $by - $ay;
            $ab2 = max(1e-9, $abx * $abx + $aby * $aby);
            $t   = max(0.0, min(1.0, (-$ax * $abx + -$ay * $aby) / $ab2));

            $px   = $ax + $abx * $t;
            $py   = $ay + $aby * $t;
            $dist = sqrt($px * $px + $py * $py);
            $segLen = sqrt($ab2);

            if (! $best || $dist < $best['distance_m']) {
                $best = [
                    'lat'           => $lat + $py / $latScale,
                    'lng'           => $lng + $px / $lngScale,
                    'distance_m'    => $dist,
                    'chainage_m'    => $chainage + $segLen * $t,
                    'segment_index' => $i,
                ];
            }

            $chainage += $segLen;
        }

        return $best ?? [
            'lat' => $lat, 'lng' => $lng,
            'distance_m' => INF, 'chainage_m' => 0.0, 'segment_index' => 0,
        ];
    }

    /**
     * Slice a plain [[lat,lng],...] path between two projection results
     * (as returned by projectPointToPath). Mirrors GeometryService::routePathBetween().
     */
    public static function routePathBetween(array $path, array $from, array $to): array
    {
        if (count($path) < 2) {
            return [[$from['lat'], $from['lng']], [$to['lat'], $to['lng']]];
        }

        $chainages = [0.0];
        for ($i = 1; $i < count($path); $i++) {
            $chainages[$i] = $chainages[$i - 1] + self::haversine(
                (float) $path[$i - 1][0], (float) $path[$i - 1][1],
                (float) $path[$i][0],     (float) $path[$i][1]
            );
        }

        $reverse = $from['chainage_m'] > $to['chainage_m'];
        $start   = $reverse ? $to   : $from;
        $end     = $reverse ? $from : $to;

        $result = [[$start['lat'], $start['lng']]];
        for ($i = max(1, (int) $start['segment_index']); $i < count($path); $i++) {
            if ($chainages[$i] <= $start['chainage_m'] + 0.01) continue;
            if ($chainages[$i] >= $end['chainage_m']   - 0.01) break;
            $result[] = [(float) $path[$i][0], (float) $path[$i][1]];
        }
        $result[] = [$end['lat'], $end['lng']];

        return $reverse ? array_reverse(self::compactPath($result)) : self::compactPath($result);
    }

    /** Remove consecutive duplicate points. */
    public static function compactPath(array $path): array
    {
        $out = [];
        foreach ($path as $pt) {
            $p    = [round((float) $pt[0], 7), round((float) $pt[1], 7)];
            $last = $out[array_key_last($out)] ?? null;
            if (! $last || abs($last[0] - $p[0]) > 1e-7 || abs($last[1] - $p[1]) > 1e-7) {
                $out[] = $p;
            }
        }
        return $out;
    }
}

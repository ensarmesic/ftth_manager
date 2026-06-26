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
}

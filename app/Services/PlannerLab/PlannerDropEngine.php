<?php

namespace App\Services\PlannerLab;

class PlannerDropEngine
{
    private int $counter = 0;

    /**
     * Generate drop paths for all house-cabinet assignments.
     * Uses the same T-shape logic as dropPathForHouse() on the main map:
     *   ODO → perp snap on nearest planned route → along route → perp snap near house → house
     * Falls back to a direct straight line when ODO is > 35 m from any route
     * or house is > 90 m from that route.
     */
    public function createDrops(
        array $cabinets,
        array $houses,
        array $plannedRoutes,
        array $options
    ): array {
        $maxDist = (float) ($options['maxDropDistance'] ?? 120);

        $houseById = [];
        foreach ($houses as $h) {
            $houseById[$h['id']] = $h;
        }

        $installation = $options['installation'] ?? 'underground';

        $drops = [];
        foreach ($cabinets as $cabinet) {
            foreach ($cabinet['house_ids'] as $houseId) {
                $house = $houseById[$houseId] ?? null;
                if (! $house) {
                    continue;
                }
                $drops[] = $this->dropPath($cabinet, $house, $plannedRoutes, $maxDist, $installation);
            }
        }

        return $drops;
    }

    protected function dropPath(
        array $cabinet,
        array $house,
        array $plannedRoutes,
        float $maxDist,
        string $installation
    ): array {
        $this->counter++;
        $name = sprintf('LAB-DROP-%03d', $this->counter);

        $directPath = [
            [(float) $cabinet['lat'], (float) $cabinet['lng']],
            [(float) $house['latitude'], (float) $house['longitude']],
        ];

        // Find nearest planned route to the cabinet
        $bestRoute    = null;
        $bestCabDist  = INF;
        $bestCabProj  = null;

        foreach ($plannedRoutes as $r) {
            $path = $r['path'] ?? [];
            if (count($path) < 2) {
                continue;
            }
            $proj = PlannerGeometry::projectPointToPath(
                (float) $cabinet['lat'], (float) $cabinet['lng'], $path
            );
            if ($proj['distance_m'] < $bestCabDist) {
                $bestCabDist = $proj['distance_m'];
                $bestCabProj = $proj;
                $bestRoute   = $r;
            }
        }

        $path   = null;
        $source = 'straight';

        if ($bestRoute !== null && $bestCabDist <= 35.0) {
            $routePath = $bestRoute['path'];
            $houseProj = PlannerGeometry::projectPointToPath(
                (float) $house['latitude'], (float) $house['longitude'], $routePath
            );

            if ($houseProj['distance_m'] <= 90.0) {
                $segment = PlannerGeometry::routePathBetween($routePath, $bestCabProj, $houseProj);
                $path    = PlannerGeometry::compactPath(array_merge(
                    [
                        [(float) $cabinet['lat'], (float) $cabinet['lng']],
                        [$bestCabProj['lat'], $bestCabProj['lng']],
                    ],
                    array_slice($segment, 1),
                    [[(float) $house['latitude'], (float) $house['longitude']]]
                ));
                $source = 'route';
            }
        }

        if ($path === null) {
            $path = $directPath;
        }

        $length  = PlannerGeometry::pathLength($path);
        $warning = null;

        if ($length > $maxDist) {
            $warning = [
                'level'   => 'warning',
                'message' => $name . ' (' . ($house['label'] ?? 'H-' . $house['id']) . '): drop je ' . round($length) . ' m (max ' . $maxDist . ' m).',
                'lat'     => $house['latitude'],
                'lng'     => $house['longitude'],
                'distance'=> $length,
            ];
        }

        return [
            'temp_id'         => 'drop-temp-' . $this->counter,
            'name'            => $name,
            'house_id'        => $house['id'],
            'house_label'     => $house['label'] ?? '',
            'cabinet_temp_id' => $cabinet['temp_id'],
            'path'            => $path,
            'length_m'        => $length,
            'source'          => $source,
            'installation'    => $installation,
            'warning'         => $warning,
            'temporary'       => true,
        ];
    }

    public function reset(): void
    {
        $this->counter = 0;
    }
}

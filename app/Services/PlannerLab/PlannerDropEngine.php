<?php

namespace App\Services\PlannerLab;

use Illuminate\Support\Facades\Http;

class PlannerDropEngine
{
    private int $counter = 0;

    public function createDrops(array $cabinets, array $houses, array $options): array
    {
        $maxDist     = $options['maxDropDistance'] ?? 120;
        $useOsm      = $options['useOsm'] ?? true;
        // osmDrops je isključen po defaultu jer N×OSRM poziva traje predugo
        $followRoads = $useOsm && ($options['osmDrops'] ?? false);

        $houseById = [];
        foreach ($houses as $h) {
            $houseById[$h['id']] = $h;
        }

        $drops = [];
        foreach ($cabinets as $cabinet) {
            foreach ($cabinet['house_ids'] as $houseId) {
                $house = $houseById[$houseId] ?? null;
                if (! $house) {
                    continue;
                }
                $drops[] = $this->dropPath($cabinet, $house, $maxDist, $followRoads, $useOsm);
            }
        }

        return $drops;
    }

    protected function dropPath(array $cabinet, array $house, float $maxDist, bool $followRoads, bool $useOsm): array
    {
        $this->counter++;
        $name = sprintf('LAB-DROP-%03d', $this->counter);

        $path = null;
        $source = 'straight';
        $followsRoad = false;
        $warning = null;

        if ($followRoads && $useOsm) {
            $path = $this->osrmRoute(
                ['lat' => $cabinet['lat'], 'lng' => $cabinet['lng']],
                ['lat' => $house['latitude'], 'lng' => $house['longitude']]
            );
            if ($path !== null) {
                $source = 'osrm';
                $followsRoad = true;
            }
        }

        if ($path === null) {
            $path = [
                [(float) $cabinet['lat'],    (float) $cabinet['lng']],
                [(float) $house['latitude'], (float) $house['longitude']],
            ];
            if ($followRoads) {
                $warning = [
                    'level'    => 'info',
                    'message'  => $name . ' (' . ($house['label'] ?? 'H-' . $house['id']) . '): drop ne prati cestu.',
                    'drop_temp_id' => 'drop-temp-' . $this->counter,
                    'house_id' => $house['id'],
                    'lat'      => $house['latitude'],
                    'lng'      => $house['longitude'],
                    'distance' => null,
                ];
            }
        }

        $length = PlannerGeometry::pathLength($path);

        if ($length > $maxDist) {
            $warning = [
                'level'        => 'warning',
                'message'      => $name . ' (' . ($house['label'] ?? 'H-' . $house['id']) . '): drop je ' . round($length) . ' m (max ' . $maxDist . ' m).',
                'drop_temp_id' => 'drop-temp-' . $this->counter,
                'house_id'     => $house['id'],
                'lat'          => $house['latitude'],
                'lng'          => $house['longitude'],
                'distance'     => $length,
            ];
        }

        return [
            'temp_id'        => 'drop-temp-' . $this->counter,
            'name'           => $name,
            'house_id'       => $house['id'],
            'house_label'    => $house['label'] ?? '',
            'cabinet_temp_id'=> $cabinet['temp_id'],
            'path'           => $path,
            'length_m'       => $length,
            'source'         => $source,
            'follows_road'   => $followsRoad,
            'warning'        => $warning,
            'temporary'      => true,
        ];
    }

    protected function osrmRoute(array $start, array $end): ?array
    {
        try {
            $url = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%f,%f;%f,%f?overview=full&geometries=geojson',
                $start['lng'], $start['lat'],
                $end['lng'],   $end['lat']
            );

            $response = Http::timeout(3)->get($url);

            if (! $response->ok()) {
                return null;
            }

            $data = $response->json();
            if (empty($data['routes'][0]['geometry']['coordinates'])) {
                return null;
            }

            return array_map(
                fn ($c) => [(float) $c[1], (float) $c[0]],
                $data['routes'][0]['geometry']['coordinates']
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function reset(): void
    {
        $this->counter = 0;
    }
}

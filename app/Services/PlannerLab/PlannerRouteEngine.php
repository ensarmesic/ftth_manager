<?php

namespace App\Services\PlannerLab;

use Illuminate\Support\Facades\Http;

class PlannerRouteEngine
{
    private int $counter = 0;

    public function routeBetween(array $start, array $end, array $options): array
    {
        $this->counter++;
        $installation = $options['installation'] ?? 'underground';
        $useOsm = $options['useOsm'] ?? true;

        $path = null;
        $source = 'straight';

        if ($useOsm) {
            $path = $this->osmRoute($start, $end);
            if ($path !== null) {
                $source = 'osrm';
            }
        }

        if ($path === null) {
            $path = $this->straightLine($start, $end);
        }

        return [
            'temp_id' => 'route-temp-' . $this->counter,
            'name' => sprintf('LAB-TR-%03d', $this->counter),
            'type' => 'distribution',
            'installation' => $installation,
            'path' => $path,
            'length_m' => PlannerGeometry::pathLength($path),
            'source' => $source,
            'temporary' => true,
        ];
    }

    protected function straightLine(array $start, array $end): array
    {
        return [
            [(float) $start['lat'], (float) $start['lng']],
            [(float) $end['lat'], (float) $end['lng']],
        ];
    }

    protected function osmRoute(array $start, array $end): ?array
    {
        try {
            $url = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%f,%f;%f,%f?overview=full&geometries=geojson',
                $start['lng'], $start['lat'],
                $end['lng'], $end['lat']
            );

            $response = Http::timeout(4)->get($url);

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

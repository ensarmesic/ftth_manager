<?php

namespace App\Services\PlannerLab;

use Illuminate\Support\Facades\Http;

class PlannerRoadEngine
{
    // Kabel mora ići uz cestu, ne kroz dvorišta/njive.
    // Foot profil pronalazi pješačke prečice kroz polja — to ne smijemo koristiti za krakove.
    // Za krakove: driving → bicycle (oba prate cestu).
    // Za dropove i snap: driving → bicycle → foot (drop može ići i stazom do kuće).

    private const BRANCH_CHAIN = [
        ['base' => 'https://router.project-osrm.org',             'profile' => 'driving'],
        ['base' => 'https://routing.openstreetmap.de/routed-car', 'profile' => 'driving'],
        ['base' => 'https://routing.openstreetmap.de/routed-bike','profile' => 'bike'],
    ];

    private const DROP_CHAIN = [
        ['base' => 'https://router.project-osrm.org',              'profile' => 'driving'],
        ['base' => 'https://routing.openstreetmap.de/routed-car',  'profile' => 'driving'],
        ['base' => 'https://routing.openstreetmap.de/routed-bike', 'profile' => 'bike'],
        ['base' => 'https://routing.openstreetmap.de/routed-foot', 'profile' => 'foot'],
    ];

    private const NEAREST_CHAIN = [
        ['base' => 'https://router.project-osrm.org',              'profile' => 'driving'],
        ['base' => 'https://routing.openstreetmap.de/routed-car',  'profile' => 'driving'],
        ['base' => 'https://routing.openstreetmap.de/routed-bike', 'profile' => 'bike'],
    ];

    /**
     * Cijeli krak u jednom pozivu: ODF → ODO-1 → … → ODO-n
     * Pokušava driving pa foot — osigurava da svi putevi budu pokriveni.
     */
    public function routeBranch(array $odf, array $cabinets, array $options = []): array
    {
        if (! ($options['useOsm'] ?? true) || ! ($options['followRoads'] ?? true)) {
            return $this->straightBranch($this->toPoints($odf, $cabinets));
        }

        $coordStr = $this->buildCoordStr($odf, $cabinets);

        foreach (self::BRANCH_CHAIN as $endpoint) {
            $result = $this->osrmRoute($endpoint['base'], $endpoint['profile'], $coordStr);
            if ($result !== null) {
                return [
                    'path'              => $result['path'],
                    'length_m'          => PlannerGeometry::pathLength($result['path']),
                    'source'            => 'osrm-' . $endpoint['profile'],
                    'follows_road'      => true,
                    'snapped_waypoints' => $result['waypoints'],
                ];
            }
        }

        return $this->straightBranch($this->toPoints($odf, $cabinets));
    }

    public function routeDrop(array $start, array $end, array $options = []): array
    {
        if (! ($options['useOsm'] ?? true) || ! ($options['followRoads'] ?? true)) {
            return $this->straightSegment($start, $end);
        }

        $coordStr = sprintf('%f,%f;%f,%f',
            $start['lng'], $start['lat'],
            $end['lng'],   $end['lat']
        );

        foreach (self::DROP_CHAIN as $endpoint) {
            $result = $this->osrmRoute($endpoint['base'], $endpoint['profile'], $coordStr);
            if ($result !== null) {
                return [
                    'path'         => $result['path'],
                    'length_m'     => PlannerGeometry::pathLength($result['path']),
                    'source'       => 'osrm-' . $endpoint['profile'],
                    'follows_road' => true,
                ];
            }
        }

        return $this->straightSegment($start, $end);
    }

    /**
     * Snap tačke na najbližu cestu/put.
     * Pokušava driving pa foot da pokrije sve tipove puteva.
     */
    public function snapToRoad(array $point): ?array
    {
        foreach (self::NEAREST_CHAIN as $endpoint) {
            $snapped = $this->osrmNearest($endpoint['base'], $endpoint['profile'], $point);
            if ($snapped !== null) {
                return $snapped;
            }
        }

        return null;
    }

    // ── Private ──────────────────────────────────────────────────────────

    private function osrmRoute(string $base, string $profile, string $coordStr): ?array
    {
        try {
            $url = sprintf(
                '%s/route/v1/%s/%s?overview=full&geometries=geojson&alternatives=false&steps=false',
                $base, $profile, $coordStr
            );

            $res = Http::timeout(12)->get($url);
            if (! $res->ok()) {
                return null;
            }

            $data   = $res->json();
            $coords = $data['routes'][0]['geometry']['coordinates'] ?? null;
            if (empty($coords)) {
                return null;
            }

            $path = array_map(fn ($c) => [(float) $c[1], (float) $c[0]], $coords);

            $waypoints = [];
            foreach ($data['waypoints'] ?? [] as $i => $wp) {
                $loc = $wp['location'] ?? null;
                if ($loc) {
                    $waypoints[$i] = ['lat' => (float) $loc[1], 'lng' => (float) $loc[0]];
                }
            }

            return compact('path', 'waypoints');
        } catch (\Throwable) {
            return null;
        }
    }

    private function osrmNearest(string $base, string $profile, array $point): ?array
    {
        try {
            $url = sprintf(
                '%s/nearest/v1/%s/%f,%f?number=1',
                $base, $profile,
                $point['lng'], $point['lat']
            );

            $res = Http::timeout(5)->get($url);
            if (! $res->ok()) {
                return null;
            }

            $loc = $res->json()['waypoints'][0]['location'] ?? null;
            if (! $loc) {
                return null;
            }

            return ['lat' => (float) $loc[1], 'lng' => (float) $loc[0]];
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildCoordStr(array $odf, array $cabinets): string
    {
        $parts = [sprintf('%f,%f', $odf['longitude'], $odf['latitude'])];
        foreach ($cabinets as $c) {
            $parts[] = sprintf('%f,%f', $c['lng'], $c['lat']);
        }
        return implode(';', $parts);
    }

    private function toPoints(array $odf, array $cabinets): array
    {
        $pts = [['lat' => (float) $odf['latitude'], 'lng' => (float) $odf['longitude']]];
        foreach ($cabinets as $c) {
            $pts[] = ['lat' => (float) $c['lat'], 'lng' => (float) $c['lng']];
        }
        return $pts;
    }

    private function straightBranch(array $points): array
    {
        $path = array_map(fn ($p) => [(float) $p['lat'], (float) $p['lng']], $points);
        return [
            'path'              => $path,
            'length_m'          => PlannerGeometry::pathLength($path),
            'source'            => 'straight',
            'follows_road'      => false,
            'snapped_waypoints' => [],
        ];
    }

    private function straightSegment(array $start, array $end): array
    {
        $path = [
            [(float) $start['lat'], (float) $start['lng']],
            [(float) $end['lat'],   (float) $end['lng']],
        ];
        return [
            'path'         => $path,
            'length_m'     => PlannerGeometry::pathLength($path),
            'source'       => 'straight',
            'follows_road' => false,
        ];
    }

    /**
     * Direktna ruta od tačke A do tačke B.
     * Wrapper oko routeBranch sa jednim "kabinetom" kao odredištem.
     */
    public function routeToPoint(array $from, array $to, array $options = []): array
    {
        if (! ($options['useOsm'] ?? true) || ! ($options['followRoads'] ?? true)) {
            $path = [[(float) $from['latitude'], (float) $from['longitude']], [(float) $to['lat'], (float) $to['lng']]];
            return [
                'path'         => $path,
                'length_m'     => PlannerGeometry::pathLength($path),
                'source'       => 'straight',
                'follows_road' => false,
                'snapped_waypoints' => [],
            ];
        }

        $coordStr = sprintf(
            '%f,%f;%f,%f',
            (float) $from['longitude'], (float) $from['latitude'],
            (float) $to['lng'],         (float) $to['lat']
        );

        foreach (self::BRANCH_CHAIN as $endpoint) {
            $result = $this->osrmRoute($endpoint['base'], $endpoint['profile'], $coordStr);
            if ($result !== null) {
                return [
                    'path'              => $result['path'],
                    'length_m'          => PlannerGeometry::pathLength($result['path']),
                    'source'            => 'osrm-' . $endpoint['profile'],
                    'follows_road'      => true,
                    'snapped_waypoints' => $result['waypoints'] ?? [],
                ];
            }
        }

        // Fallback — ravna linija
        $path = [
            [(float) $from['latitude'],  (float) $from['longitude']],
            [(float) $to['lat'],         (float) $to['lng']],
        ];
        return [
            'path'              => $path,
            'length_m'          => PlannerGeometry::pathLength($path),
            'source'            => 'straight',
            'follows_road'      => false,
            'snapped_waypoints' => [],
        ];
    }

    public function reset(): void {}
}

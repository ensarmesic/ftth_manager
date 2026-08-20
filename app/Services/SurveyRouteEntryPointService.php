<?php

namespace App\Services;

use InvalidArgumentException;

class SurveyRouteEntryPointService
{
    /** Five centimetres is reserved for duplicate survey-coordinate precision. */
    public const ENDPOINT_TOLERANCE_M = 0.05;

    public function __construct(private readonly GeometryService $geometry) {}

    /**
     * Return the last confirmed place where the ordered customer geometry enters a main route.
     *
     * @param  array<int,array{0:float|int,1:float|int}>  $userPath
     * @param  array<int,array{id:int|string,path:array}>  $mainRoutes
     * @return null|array{entry_point:array{0:float,1:float},user_segment_end_index:int,main_route_id:int|string,main_segment_index:int,match_type:string}
     */
    public function find(array $userPath, array $mainRoutes, float $endpointToleranceMeters = self::ENDPOINT_TOLERANCE_M): ?array
    {
        if ($endpointToleranceMeters < 0 || $endpointToleranceMeters > self::ENDPOINT_TOLERANCE_M) {
            throw new InvalidArgumentException('Tolerancija entry pointa mora biti između 0 i 0.05 m.');
        }
        if (count($userPath) < 2) {
            return null;
        }

        $matches = [];
        for ($userSegment = 1; $userSegment < count($userPath); $userSegment++) {
            $userStart = $this->point($userPath[$userSegment - 1]);
            $userEnd = $this->point($userPath[$userSegment]);

            foreach ($mainRoutes as $route) {
                $mainPath = array_values($route['path'] ?? []);
                if (! array_key_exists('id', $route) || count($mainPath) < 2) {
                    continue;
                }
                for ($mainSegment = 1; $mainSegment < count($mainPath); $mainSegment++) {
                    $mainStart = $this->point($mainPath[$mainSegment - 1]);
                    $mainEnd = $this->point($mainPath[$mainSegment]);
                    $intersection = $this->segmentIntersection($userStart, $userEnd, $mainStart, $mainEnd);
                    if ($intersection !== null) {
                        $matches[] = $this->match(
                            $intersection['point'],
                            $userSegment,
                            $intersection['user_fraction'],
                            $route['id'],
                            $mainSegment - 1,
                            'intersection',
                        );

                        continue;
                    }

                    foreach ([[$userStart, 0.0], [$userEnd, 1.0]] as [$userEndpoint, $fraction]) {
                        foreach ([$mainStart, $mainEnd] as $mainEndpoint) {
                            if ($this->geometry->distanceBetweenPoints($userEndpoint, $mainEndpoint) <= $endpointToleranceMeters) {
                                $matches[] = $this->match(
                                    $userEndpoint,
                                    $userSegment,
                                    $fraction,
                                    $route['id'],
                                    $mainSegment - 1,
                                    'shared_endpoint',
                                );
                            }
                        }
                    }
                }
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, fn (array $left, array $right) => $right['_position'] <=> $left['_position']);
        $match = $matches[0];
        unset($match['_position']);

        return $match;
    }

    private function segmentIntersection(array $a, array $b, array $c, array $d): ?array
    {
        $denominator = (($a[1] - $b[1]) * ($c[0] - $d[0])) - (($a[0] - $b[0]) * ($c[1] - $d[1]));
        if (abs($denominator) < 1e-14) {
            return null;
        }

        $t = ((($a[1] - $c[1]) * ($c[0] - $d[0])) - (($a[0] - $c[0]) * ($c[1] - $d[1]))) / $denominator;
        $u = -((($a[1] - $b[1]) * ($a[0] - $c[0])) - (($a[0] - $b[0]) * ($a[1] - $c[1]))) / $denominator;
        $epsilon = 1e-10;
        if ($t < -$epsilon || $t > 1 + $epsilon || $u < -$epsilon || $u > 1 + $epsilon) {
            return null;
        }

        $t = max(0.0, min(1.0, $t));

        return [
            'point' => [
                $a[0] + (($b[0] - $a[0]) * $t),
                $a[1] + (($b[1] - $a[1]) * $t),
            ],
            'user_fraction' => $t,
        ];
    }

    private function match(array $point, int $userSegment, float $fraction, int|string $routeId, int $mainSegment, string $type): array
    {
        return [
            'entry_point' => $point,
            'user_segment_end_index' => $userSegment,
            'main_route_id' => $routeId,
            'main_segment_index' => $mainSegment,
            'match_type' => $type,
            '_position' => ($userSegment - 1) + $fraction,
        ];
    }

    private function point(array $point): array
    {
        return [(float) $point[0], (float) $point[1]];
    }
}

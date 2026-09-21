<?php

namespace App\Services;

use App\Models\Project;

class AsBuiltComparisonService
{
    public function build(Project $project): array
    {
        $routeQuery = $project->routes();
        $houseQuery = $project->houses();
        $plannedRouteLength = (float) (clone $routeQuery)->sum('duct_length_m');
        $builtRouteLength = (float) (clone $routeQuery)->whereIn('status', ['completed', 'built', 'as_built'])->sum('duct_length_m');
        $plannedHouses = (clone $houseQuery)->count();
        $connectedHouses = (clone $houseQuery)->whereIn('status', ['connected', 'completed', 'built', 'as_built'])->count();
        $plannedRoutes = (clone $routeQuery)->count();
        $builtRoutes = (clone $routeQuery)->whereIn('status', ['completed', 'built', 'as_built'])->count();

        return [
            'routes' => ['planned' => $plannedRoutes, 'built' => $builtRoutes, 'percent' => $this->percent($builtRoutes, $plannedRoutes)],
            'route_length_m' => ['planned' => $plannedRouteLength, 'built' => $builtRouteLength, 'variance' => $builtRouteLength - $plannedRouteLength, 'percent' => $this->percent($builtRouteLength, $plannedRouteLength)],
            'houses' => ['planned' => $plannedHouses, 'built' => $connectedHouses, 'percent' => $this->percent($connectedHouses, $plannedHouses)],
        ];
    }

    private function percent(float|int $actual, float|int $planned): int
    {
        return $planned > 0 ? min(100, (int) round($actual / $planned * 100)) : 0;
    }
}

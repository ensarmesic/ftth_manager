<?php

namespace App\Services;

use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;

class ProjectMaterialService
{
    public function summary(Project $project, int $reservePercent = 10): array
    {
        $routes = $project->routes;
        $cableRoutes = $routes->where('route_type', '!=', 'trench');
        $summary = [
            'odf_count' => $project->odfs->count(),
            'odo_count' => $project->cabinets->count(),
            'house_count' => $project->houses->count(),
            'splitter_count' => $project->cabinets->sum('splitter_count'),
            'route_length_m' => $cableRoutes->sum('duct_length_m'),
            'microduct_14_10_m' => $cableRoutes->where('microduct_type', '14/10')->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count),
            'microduct_10_8_m' => $cableRoutes->where('microduct_type', '10/8')->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count),
            'fiber_4_m' => $cableRoutes->where('fiber_count', 4)->sum('fiber_length_m'),
            'fiber_12_m' => $cableRoutes->where('fiber_count', 12)->sum('fiber_length_m'),
            'fiber_24_m' => $cableRoutes->where('fiber_count', 24)->sum('fiber_length_m'),
            'fiber_48_m' => $cableRoutes->where('fiber_count', 48)->sum('fiber_length_m'),
            'unclassified_routes' => $cableRoutes->filter(fn (NetworkRoute $route) => ! $route->hasCompleteMicroductData() || $route->hasIncompleteCableData())->count(),
        ];
        $summary['estimated_value'] = (float) $project->materials()->selectRaw('SUM(planned_quantity * unit_price) as total')->value('total') ?: 0.0;
        $summary['reserve_percent'] = $reservePercent;
        $summary['lengths_with_reserve'] = collect($summary)
            ->filter(fn ($value, $key) => is_numeric($value) && (str_ends_with($key, '_m') || $key === 'route_length_m'))
            ->map(fn ($value) => [
                'base' => $value,
                'reserve' => (int) ceil($value * $reservePercent / 100),
                'total' => (int) ceil($value * (1 + $reservePercent / 100)),
            ])->all();
        $summary['microduct_by_type'] = $cableRoutes->groupBy('microduct_type')
            ->filter(fn ($group, $type) => filled($type))
            ->map(fn ($group) => $group->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count))->all();
        $summary['fiber_by_count'] = $cableRoutes->groupBy('fiber_count')
            ->filter(fn ($group, $count) => filled($count))
            ->map->sum('fiber_length_m')->all();

        return $summary;
    }

    public function routeOccupancy(NetworkRoute $route, array $housesPerCabinet = []): array
    {
        $capacity = (int) ($route->fiber_count ?? 0);
        $used = $route->route_type === 'drop'
            ? ($route->to_type === 'house' && $route->to_id ? 1 : 0)
            : ($route->cabinet_id
                ? ($housesPerCabinet
                    ? (int) ($housesPerCabinet[$route->cabinet_id] ?? 0)
                    : House::where('cabinet_id', $route->cabinet_id)->count())
                : 0);

        return [
            'fiber_capacity' => $capacity,
            'used_fibers' => $used,
            'free_fibers' => max($capacity - $used, 0),
            'utilization_percent' => $capacity > 0 ? (int) round($used / $capacity * 100) : 0,
        ];
    }

    public function splitterCount(int $houseCount): int
    {
        return max(1, min(3, (int) ceil($houseCount / 4)));
    }
}

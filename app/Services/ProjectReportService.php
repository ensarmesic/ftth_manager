<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Material;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Numbers behind the Izvještaji page: a short row per project and a full report for one project. */
class ProjectReportService
{
    public const ROUTE_TYPES = [
        'trench' => 'Glavni rov',
        'backbone' => 'Backbone',
        'feeder' => 'Primarni (feeder)',
        'distribution' => 'Distribucija',
        'drop' => 'Drop (priključak)',
    ];

    public function __construct(
        private readonly ProjectHealthService $health,
        private readonly ProjectMaterialService $materials,
    ) {}

    /**
     * One row per project for the overview table, computed with a handful of grouped queries.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    public function overview(Collection $projects): array
    {
        $ids = $projects->pluck('id')->all();
        $connected = House::whereIn('project_id', $ids)->whereNotNull('cabinet_id')
            ->selectRaw('project_id, COUNT(*) as total')->groupBy('project_id')->pluck('total', 'project_id');
        $capacity = Cabinet::whereIn('project_id', $ids)
            ->selectRaw('project_id, SUM(splitter_count * ports_per_splitter) as total')->groupBy('project_id')->pluck('total', 'project_id');
        $routeLengths = NetworkRoute::whereIn('project_id', $ids)
            ->selectRaw("project_id, SUM(CASE WHEN route_type = 'trench' THEN duct_length_m ELSE 0 END) as trench, SUM(CASE WHEN route_type <> 'trench' THEN duct_length_m ELSE 0 END) as cable, SUM(CASE WHEN route_type <> 'trench' THEN fiber_length_m ELSE 0 END) as fiber")
            ->groupBy('project_id')->get()->keyBy('project_id');
        $health = $this->health->forProjects($projects);

        $rows = $projects->map(function (Project $project) use ($connected, $capacity, $routeLengths, $health): array {
            $houses = (int) $project->houses_count;
            $linked = (int) ($connected[$project->id] ?? 0);
            $ports = (int) ($capacity[$project->id] ?? 0);
            $lengths = $routeLengths[$project->id] ?? null;

            return [
                'project' => $project,
                'odfs' => (int) $project->odfs_count,
                'cabinets' => (int) $project->cabinets_count,
                'houses' => $houses,
                'connected' => $linked,
                'connected_percent' => $houses > 0 ? (int) round($linked / $houses * 100) : 0,
                'capacity' => $ports,
                'utilization' => $ports > 0 ? min(100, (int) round($linked / $ports * 100)) : 0,
                'trench_m' => (float) ($lengths->trench ?? 0),
                'cable_m' => (float) ($lengths->cable ?? 0),
                'fiber_m' => (float) ($lengths->fiber ?? 0),
                'errors' => $health[$project->id]['errors'] ?? 0,
                'warnings' => $health[$project->id]['warnings'] ?? 0,
            ];
        })->values()->all();

        $sum = fn (string $key) => array_sum(array_column($rows, $key));

        return [
            'rows' => $rows,
            'totals' => [
                'projects' => count($rows),
                'houses' => $sum('houses'),
                'connected' => $sum('connected'),
                'cabinets' => $sum('cabinets'),
                'capacity' => $sum('capacity'),
                'cable_m' => $sum('cable_m'),
                'trench_m' => $sum('trench_m'),
                'errors' => $sum('errors'),
                'warnings' => $sum('warnings'),
            ],
        ];
    }

    /** Full report for one project. */
    public function detail(Project $project): array
    {
        $project->load([
            'routes',
            'odfs',
            'houses',
            'materials',
            'cabinets' => fn ($query) => $query->withCount('houses')->with('odf')->orderBy('name'),
        ]);
        $houses = $project->houses;
        $cabinets = $project->cabinets;
        $routes = $project->routes;
        $cableRoutes = $routes->where('route_type', '!=', 'trench');

        $capacity = (int) $cabinets->sum(fn (Cabinet $cabinet) => $cabinet->capacity);
        $connected = $houses->whereNotNull('cabinet_id')->count();
        $used = (int) $cabinets->sum('houses_count');
        $fiber = (float) $cableRoutes->sum('fiber_length_m');

        $cabinetRows = $cabinets->map(fn (Cabinet $cabinet) => [
            'id' => $cabinet->id,
            'name' => $cabinet->name,
            'odf' => $cabinet->odf?->name,
            'splitters' => (int) $cabinet->splitter_count,
            'capacity' => $cabinet->capacity,
            'used' => (int) $cabinet->houses_count,
            'free' => max($cabinet->capacity - (int) $cabinet->houses_count, 0),
            'utilization' => $cabinet->capacity > 0 ? (int) round($cabinet->houses_count / $cabinet->capacity * 100) : 0,
            'overfull' => $cabinet->houses_count > $cabinet->capacity,
        ])->sortByDesc('utilization')->values();

        $odfRows = $project->odfs->sortBy('name')->map(function (Odf $odf) use ($cabinets): array {
            $own = $cabinets->where('odf_id', $odf->id);

            return [
                'name' => $odf->name,
                'address' => $odf->address,
                'ports' => (int) $odf->port_count,
                'fibers' => (int) $odf->fiber_capacity,
                'cabinets' => $own->count(),
                'houses' => (int) $own->sum('houses_count'),
            ];
        })->values();

        $routesByType = collect(self::ROUTE_TYPES)->map(function (string $label, string $type) use ($routes): array {
            $group = $routes->where('route_type', $type);

            return [
                'type' => $type,
                'label' => $label,
                'count' => $group->count(),
                'length_m' => (float) $group->sum('duct_length_m'),
                'fiber_m' => $type === 'trench' ? 0.0 : (float) $group->sum('fiber_length_m'),
                'underground_m' => (float) $group->where('installation_type', '!=', 'aerial')->sum('duct_length_m'),
                'aerial_m' => (float) $group->where('installation_type', 'aerial')->sum('duct_length_m'),
            ];
        })->filter(fn (array $row) => $row['count'] > 0)->values();

        $materialRows = $project->materials->sortBy('name')->map(fn (Material $material) => [
            'name' => $material->name,
            'unit' => $material->unit,
            'planned' => (float) $material->planned_quantity,
            'used' => (float) $material->used_quantity,
            'unit_price' => (float) $material->unit_price,
            'total' => (float) $material->planned_quantity * (float) $material->unit_price,
        ])->values();

        $health = $this->health->forProject($project);

        return [
            'summary' => [
                'odfs' => $project->odfs->count(),
                'cabinets' => $cabinets->count(),
                'houses' => $houses->count(),
                'connected' => $connected,
                'unassigned' => $houses->count() - $connected,
                'connected_percent' => $houses->count() > 0 ? (int) round($connected / $houses->count() * 100) : 0,
                'capacity' => $capacity,
                'used_ports' => $used,
                'free_ports' => (int) $cabinetRows->sum('free'),
                'utilization' => $capacity > 0 ? min(100, (int) round($used / $capacity * 100)) : 0,
                'overfull_cabinets' => $cabinetRows->where('overfull', true)->count(),
                'splitters' => (int) $cabinets->sum('splitter_count'),
                'trench_m' => (float) $routes->where('route_type', 'trench')->sum('duct_length_m'),
                'cable_route_m' => (float) $cableRoutes->sum('duct_length_m'),
                'fiber_m' => $fiber,
                'fiber_reserve_m' => (int) ceil($fiber * 1.1),
                'route_count' => $cableRoutes->count(),
            ],
            'cabinets' => $cabinetRows->all(),
            'odfs' => $odfRows->all(),
            'routes_by_type' => $routesByType->all(),
            'routes' => $cableRoutes->sortBy('name')->values()->all(),
            'materials' => $this->materials->summary($project),
            'material_rows' => $materialRows->all(),
            'material_total' => (float) $materialRows->sum('total'),
            'health' => $health,
            'activity' => [
                'last_change' => collect([
                    $project->updated_at,
                    $routes->max('updated_at'),
                    $cabinets->max('updated_at'),
                    $houses->max('updated_at'),
                ])->filter()->max(),
                'generated_at' => now(),
            ],
            'survey_points' => (int) DB::table('survey_points')->where('project_id', $project->id)->count(),
        ];
    }
}

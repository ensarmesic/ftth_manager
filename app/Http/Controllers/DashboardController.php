<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $projects = Project::query()
            ->withCount([
                'odfs', 'cabinets', 'houses', 'routes',
                'houses as unassigned_houses_count' => fn ($query) => $query->whereNull('cabinet_id'),
                'cabinets as unlinked_cabinets_count' => fn ($query) => $query->whereNull('odf_id'),
                'routes as incomplete_routes_count' => fn ($query) => $query
                    ->where('route_type', '!=', 'trench')
                    ->where(fn ($route) => $route->whereNull('microduct_type')->orWhereNull('fiber_count')),
            ])
            ->latest('updated_at')
            ->get();

        $cabinets = Cabinet::query()->withCount('houses')->get();
        $capacity = $cabinets->sum(fn (Cabinet $cabinet) => $cabinet->capacity);
        $usedPorts = $cabinets->sum('houses_count');
        $routeLength = (float) NetworkRoute::query()->where('route_type', '!=', 'drop')->sum('duct_length_m');
        $connectedHouses = House::query()->whereNotNull('cabinet_id')->count();
        $totalHouses = House::count();

        $projectCards = $projects->map(function (Project $project) use ($cabinets): array {
            $projectCabinets = $cabinets->where('project_id', $project->id);
            $projectCapacity = $projectCabinets->sum(fn (Cabinet $cabinet) => $cabinet->capacity);
            $projectUsed = $projectCabinets->sum('houses_count');

            return [
                'model' => $project,
                'capacity' => $projectCapacity,
                'used' => $projectUsed,
                'utilization' => $projectCapacity > 0 ? min((int) round($projectUsed / $projectCapacity * 100), 100) : 0,
                'issues' => $project->unassigned_houses_count + $project->unlinked_cabinets_count + $project->incomplete_routes_count,
            ];
        });

        return view('ftth.dashboard', [
            'stats' => [
                'projects' => $projects->count(),
                'odfs' => Odf::count(),
                'cabinets' => $cabinets->count(),
                'houses' => $totalHouses,
                'route_km' => $routeLength / 1000,
                'total_ports' => $capacity,
                'used_ports' => $usedPorts,
                'free_ports' => max($capacity - $usedPorts, 0),
                'connected_percent' => $totalHouses > 0 ? (int) round($connectedHouses / $totalHouses * 100) : 0,
                'capacity_percent' => $capacity > 0 ? min((int) round($usedPorts / $capacity * 100), 100) : 0,
            ],
            'projectCards' => $projectCards,
            'attentionProjects' => $projectCards->where('issues', '>', 0)->sortByDesc('issues')->take(5),
            'recentActivity' => ActivityLog::query()->with('user')->latest()->limit(8)->get(),
            'latestSnapshots' => ProjectSnapshot::query()->with('project')->latest()->limit(5)->get(),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $projectId = $request->integer('project');
        $scopeToProject = fn ($query) => $query->when(
            $projectId > 0,
            fn ($projectQuery) => $projectQuery->where('project_id', $projectId)
        );

        $unlinkedHouses = $scopeToProject(House::query())->whereNull('cabinet_id')->count();
        $unlinkedCabinets = $scopeToProject(Cabinet::query())->whereNull('odf_id')->count();
        $incompleteRoutes = $scopeToProject(NetworkRoute::query())
            ->where('route_type', '!=', 'trench')
            ->where(fn ($query) => $query->whereNull('microduct_type')->orWhereNull('fiber_count'))
            ->count();

        $items = [];
        if ($unlinkedHouses) {
            $items[] = "$unlinkedHouses kuca nema dodijeljeni ODO.";
        }
        if ($unlinkedCabinets) {
            $items[] = "$unlinkedCabinets ODO ormarica nema povezani ODF.";
        }
        if ($incompleteRoutes) {
            $items[] = "$incompleteRoutes trasa nema kompletne tehnicke podatke.";
        }

        return response()->json(['count' => count($items), 'items' => $items]);
    }
}

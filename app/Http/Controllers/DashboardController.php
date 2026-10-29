<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Services\ProjectHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(ProjectHealthService $health): View
    {
        $projects = Project::query()
            ->withCount([
                'odfs', 'cabinets', 'houses', 'routes',
                'houses as unassigned_houses_count' => fn ($query) => $query->whereNull('cabinet_id'),
            ])
            ->latest('updated_at')
            ->get();

        $capacityByProject = Cabinet::query()
            ->select('project_id')
            ->selectRaw('SUM(splitter_count * ports_per_splitter) as total_capacity')
            ->groupBy('project_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->project_id => (int) $row->total_capacity]);
        $capacity = (int) $capacityByProject->sum();
        $usedPorts = (int) $projects->sum(fn (Project $project) => $project->houses_count - $project->unassigned_houses_count);
        $routeLength = (float) NetworkRoute::query()->where('route_type', '!=', 'drop')->sum('duct_length_m');
        $connectedHouses = $usedPorts;
        $totalHouses = (int) $projects->sum('houses_count');

        $healthByProject = $health->forProjects($projects);
        $projectCards = $projects->map(function (Project $project) use ($capacityByProject, $healthByProject): array {
            $projectCapacity = (int) ($capacityByProject[$project->id] ?? 0);
            $projectUsed = (int) ($project->houses_count - $project->unassigned_houses_count);

            return [
                'model' => $project,
                'capacity' => $projectCapacity,
                'used' => $projectUsed,
                'utilization' => $projectCapacity > 0 ? min((int) round($projectUsed / $projectCapacity * 100), 100) : 0,
                // Same errors + warnings as Provjera projekta and Izvještaji.
                'issues' => $healthByProject[$project->id]['issues'],
                'errors' => $healthByProject[$project->id]['errors'],
            ];
        });

        $stats = [
            'projects' => $projects->count(),
            'odfs' => (int) $projects->sum('odfs_count'),
            'cabinets' => (int) $projects->sum('cabinets_count'),
            'houses' => $totalHouses,
            'route_km' => $routeLength / 1000,
            'total_ports' => $capacity,
            'used_ports' => $usedPorts,
            'free_ports' => max($capacity - $usedPorts, 0),
            'connected_percent' => $totalHouses > 0 ? (int) round($connectedHouses / $totalHouses * 100) : 0,
            'capacity_percent' => $capacity > 0 ? min((int) round($usedPorts / $capacity * 100), 100) : 0,
        ];
        if (! app()->runningUnitTests()) {
            $stats = Cache::remember('dashboard:aggregates:v1', now()->addSeconds(30), fn () => $stats);
        }

        return view('ftth.dashboard', [
            'stats' => $stats,
            'projectCards' => $projectCards,
            'attentionProjects' => $projectCards->where('issues', '>', 0)->sortByDesc('issues')->take(5),
            'recentActivity' => ActivityLog::query()->with('user')->latest()->limit(8)->get(),
            // Without the payload: sorting whole snapshots runs MySQL out of sort memory.
            'latestSnapshots' => ProjectSnapshot::query()->select(['id', 'project_id', 'label', 'created_at'])
                ->with('project:id,name')->latest()->limit(5)->get(),
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
        $failureMarker = storage_path('app/private/health/scheduler-failure.json');
        if ($request->user()?->isAdministrator() && File::isFile($failureMarker)) {
            $failure = json_decode(File::get($failureMarker), true);
            $items[] = 'Zakazani zadatak nije uspio: '.($failure['task'] ?? 'nepoznat zadatak').'. Provjeri sistemski log.';
        }

        return response()->json(['count' => count($items), 'items' => $items]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Services\PlannerLab\PlannerLabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlannerLabController extends Controller
{
    public function __construct(protected PlannerLabService $plannerLab) {}

    public function index(): View
    {
        $projects = Project::orderBy('name')->get(['id', 'name', 'code', 'location']);
        return view('planner-lab.index', compact('projects'));
    }

    public function projectData(Project $project): JsonResponse
    {
        $project->load(['odfs', 'houses', 'cabinets', 'routes']);

        return response()->json([
            'project' => $project->only(['id', 'name', 'code', 'location']),
            'odfs' => $project->odfs->map(fn ($odf) => [
                'id' => $odf->id,
                'name' => $odf->name,
                'latitude' => $odf->latitude,
                'longitude' => $odf->longitude,
                'fiber_capacity' => $odf->fiber_capacity,
            ]),
            'houses' => $project->houses->map(fn ($h) => [
                'id' => $h->id,
                'label' => $h->label,
                'address' => $h->address,
                'latitude' => $h->latitude,
                'longitude' => $h->longitude,
                'cabinet_id' => $h->cabinet_id,
                'status' => $h->status,
            ]),
            'cabinets' => $project->cabinets->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'latitude' => $c->latitude,
                'longitude' => $c->longitude,
                'odf_id' => $c->odf_id,
                'splitter_count' => $c->splitter_count,
                'ports_per_splitter' => $c->ports_per_splitter,
            ]),
            'routes' => $project->routes->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'route_type' => $r->route_type,
                'path' => $r->path,
                'microduct_type' => $r->microduct_type,
                'fiber_count' => $r->fiber_count,
            ]),
        ]);
    }

    public function preview(Request $request, Project $project): JsonResponse
    {
        set_time_limit(180);

        $options = $request->validate([
            'useOsm'             => 'boolean',
            'followRoads'        => 'boolean',
            'maxHousesPerCabinet'=> 'integer|min:1|max:12',
            'odoSpacing'         => 'integer|min:80|max:400',
            'maxDropDistance'    => 'integer|min:30|max:400',
            'installation'       => 'string|in:underground,aerial',
        ]);

        $plan = $this->plannerLab->preview($project, $options);

        return response()->json($plan);
    }

    public function savePlan(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate(['plan' => 'required|array']);
        $plan = $validated['plan'];

        $odf = $project->odfs()->first();
        if (! $odf) {
            return response()->json(['success' => false, 'message' => 'Projekt nema ODF.'], 422);
        }

        $counts = DB::transaction(function () use ($plan, $project, $odf) {
            $savedCabinets = 0;
            $savedRoutes   = 0;
            $updatedHouses = 0;
            $tempToReal    = [];

            foreach ($plan['planned_cabinets'] ?? [] as $cab) {
                $cabinet = Cabinet::create([
                    'project_id'         => $project->id,
                    'odf_id'             => $odf->id,
                    'name'               => $cab['name'],
                    'latitude'           => $cab['lat'],
                    'longitude'          => $cab['lng'],
                    'splitter_count'     => $cab['splitters'] ?? 1,
                    'ports_per_splitter' => 4,
                ]);
                $tempToReal[$cab['temp_id']] = $cabinet->id;
                $savedCabinets++;
            }

            foreach ($plan['planned_routes'] ?? [] as $route) {
                NetworkRoute::create([
                    'project_id'        => $project->id,
                    'odf_id'            => $odf->id,
                    'name'              => $route['name'],
                    'route_type'        => 'distribution',
                    'installation_type' => $route['installation'] ?? 'underground',
                    'path'              => $route['path'],
                    'fiber_count'       => 12,
                    'microduct_count'   => 1,
                    'microduct_type'    => '7x1.2',
                ]);
                $savedRoutes++;
            }

            foreach ($plan['house_assignments'] ?? [] as $ha) {
                $realId = $tempToReal[$ha['cabinet_temp_id']] ?? null;
                if ($realId) {
                    House::where('id', $ha['house_id'])
                        ->where('project_id', $project->id)
                        ->update(['cabinet_id' => $realId]);
                    $updatedHouses++;
                }
            }

            return compact('savedCabinets', 'savedRoutes', 'updatedHouses');
        });

        return response()->json([
            'success'        => true,
            'saved_cabinets' => $counts['savedCabinets'],
            'saved_routes'   => $counts['savedRoutes'],
            'updated_houses' => $counts['updatedHouses'],
            'message'        => "Sačuvano: {$counts['savedCabinets']} ODO, {$counts['savedRoutes']} trasa, {$counts['updatedHouses']} kuća.",
        ]);
    }

    public function exportJson(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate(['plan' => 'required|array']);

        return response()->json([
            'success' => true,
            'filename' => 'planner-lab-' . $project->id . '-' . now()->format('Ymd-His') . '.json',
            'data' => $validated['plan'],
        ]);
    }
}

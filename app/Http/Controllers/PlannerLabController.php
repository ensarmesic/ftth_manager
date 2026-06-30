<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Services\BranchSyncService;
use App\Services\PlannerLab\PlannerLabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlannerLabController extends Controller
{
    public function __construct(
        protected PlannerLabService $plannerLab,
        protected BranchSyncService $branchSync,
    ) {}

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
            'road_polylines'       => 'nullable|array',
            'road_polylines.*'     => 'array',
            'excluded_polygons'    => 'nullable|array',
            'excluded_polygons.*'  => 'array',
        ]);

        $plan = $this->plannerLab->preview($project, $options);

        return response()->json($plan);
    }

    public function savePlan(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate(['plan' => 'required|array']);
        $plan = $validated['plan'];

        $odfs = $project->odfs()->whereNotNull('latitude')->whereNotNull('longitude')->get();
        if ($odfs->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Projekt nema ODF.'], 422);
        }

        $counts = DB::transaction(function () use ($plan, $project, $odfs) {
            $savedCabinets = 0;
            $savedRoutes   = 0;
            $savedDrops    = 0;
            $updatedHouses = 0;
            $tempToReal    = [];

            $nearestOdfId = function (float $lat, float $lng) use ($odfs): int {
                return $odfs->sortBy(fn ($o) => pow((float) $o->latitude - $lat, 2) + pow((float) $o->longitude - $lng, 2))->first()->id;
            };

            foreach ($plan['planned_cabinets'] ?? [] as $cab) {
                $odfId = $nearestOdfId((float) $cab['lat'], (float) $cab['lng']);
                $cabinet = Cabinet::create([
                    'project_id'         => $project->id,
                    'odf_id'             => $odfId,
                    'name'               => $this->uniqueName(Cabinet::class, $project->id, $cab['name']),
                    'address'            => 'Planner Lab ' . round((float) $cab['lat'], 5) . ', ' . round((float) $cab['lng'], 5),
                    'latitude'           => $cab['lat'],
                    'longitude'          => $cab['lng'],
                    'splitter_count'     => $cab['splitters'] ?? 1,
                    'ports_per_splitter' => 4,
                ]);
                $tempToReal[$cab['temp_id']] = $cabinet->id;
                $savedCabinets++;
            }

            // Krak (branch_temp_id) → ruta. Svaki planirani krak postaje JEDNA NetworkRoute
            // i JEDNA NetworkBranch; svi ODO-i tog kraka se vežu na tu granu preko
            // cabinets.branch_id (isti obrazac kao kad korisnik ručno doda ODO na granu
            // na glavnoj mapi — vidi CabinetController::storeCabinet).
            $consumedRouteTempIds = [];

            foreach ($plan['planned_branches'] ?? [] as $branch) {
                $branchTempId = $branch['temp_id'] ?? null;
                $route = collect($plan['planned_routes'] ?? [])
                    ->first(fn ($r) => ($r['branch_temp_id'] ?? null) === $branchTempId);

                if (! $route) {
                    continue;
                }
                $consumedRouteTempIds[] = $route['temp_id'] ?? null;

                $length    = (int) round((float) ($route['length_m'] ?? 0));
                $routePath = $route['path'] ?? [];
                $midPoint  = $routePath[intval(count($routePath) / 2)] ?? ($routePath[0] ?? null);
                $odfId     = $midPoint ? $nearestOdfId((float) $midPoint[0], (float) $midPoint[1]) : $odfs->first()->id;

                $createdRoute = NetworkRoute::create([
                    'project_id'        => $project->id,
                    'odf_id'            => $odfId,
                    'from_type'         => 'odf',
                    'from_id'           => $odfId,
                    'name'              => $this->uniqueName(NetworkRoute::class, $project->id, $route['name']),
                    'route_type'        => 'distribution',
                    'installation_type' => $route['installation'] ?? 'underground',
                    'path'              => $route['path'],
                    'duct_length_m'     => $length,
                    'fiber_length_m'    => $length,
                    'fiber_count'       => 12,
                    'microduct_count'   => 1,
                    'microduct_type'    => '14/10',
                ]);
                $savedRoutes++;

                $createdBranch = $this->branchSync->createBranchForRoute($createdRoute);

                if ($createdBranch) {
                    foreach (($branch['cabinets'] ?? []) as $order => $cab) {
                        $realId = $tempToReal[$cab['temp_id'] ?? ''] ?? null;
                        if ($realId) {
                            Cabinet::where('id', $realId)->update([
                                'branch_id'    => $createdBranch->id,
                                'branch_order' => $order,
                            ]);
                        }
                    }
                }
            }

            // Rute koje (zbog nekonzistentnih podataka) nisu povezane ni s jednim krakom —
            // ipak ih sačuvaj da se geometrija ne izgubi, samo bez grane.
            foreach ($plan['planned_routes'] ?? [] as $route) {
                if (in_array($route['temp_id'] ?? null, $consumedRouteTempIds, true)) {
                    continue;
                }

                $length    = (int) round((float) ($route['length_m'] ?? 0));
                $routePath = $route['path'] ?? [];
                $midPoint  = $routePath[intval(count($routePath) / 2)] ?? ($routePath[0] ?? null);
                $odfId     = $midPoint ? $nearestOdfId((float) $midPoint[0], (float) $midPoint[1]) : $odfs->first()->id;

                NetworkRoute::create([
                    'project_id'        => $project->id,
                    'odf_id'            => $odfId,
                    'from_type'         => 'odf',
                    'from_id'           => $odfId,
                    'name'              => $this->uniqueName(NetworkRoute::class, $project->id, $route['name']),
                    'route_type'        => 'distribution',
                    'installation_type' => $route['installation'] ?? 'underground',
                    'path'              => $route['path'],
                    'duct_length_m'     => $length,
                    'fiber_length_m'    => $length,
                    'fiber_count'       => 12,
                    'microduct_count'   => 1,
                    'microduct_type'    => '14/10',
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

            foreach ($plan['planned_drops'] ?? [] as $drop) {
                $realCabId = $tempToReal[$drop['cabinet_temp_id']] ?? null;
                if (! $realCabId) {
                    continue;
                }
                $dropLength = (int) round((float) ($drop['length_m'] ?? 0));
                $dropPath  = $drop['path'] ?? [];
                $dropMid   = $dropPath[0] ?? null;
                $dropOdfId = $dropMid ? $nearestOdfId((float) $dropMid[0], (float) $dropMid[1]) : $odfs->first()->id;
                NetworkRoute::create([
                    'project_id'        => $project->id,
                    'odf_id'            => $dropOdfId,
                    'cabinet_id'        => $realCabId,
                    'name'              => $this->uniqueName(NetworkRoute::class, $project->id, $drop['name']),
                    'route_type'        => 'drop',
                    'installation_type' => $drop['installation'] ?? 'underground',
                    'path'              => $drop['path'],
                    'duct_length_m'     => $dropLength,
                    'fiber_length_m'    => $dropLength,
                    'fiber_count'       => 4,
                    'microduct_count'   => 1,
                    'microduct_type'    => '10/8',
                    'from_type'         => 'cabinet',
                    'from_id'           => $realCabId,
                    'to_type'           => 'house',
                    'to_id'             => $drop['house_id'],
                ]);
                $savedDrops++;
            }

            return compact('savedCabinets', 'savedRoutes', 'savedDrops', 'updatedHouses');
        });

        return response()->json([
            'success'        => true,
            'saved_cabinets' => $counts['savedCabinets'],
            'saved_routes'   => $counts['savedRoutes'],
            'saved_drops'    => $counts['savedDrops'],
            'updated_houses' => $counts['updatedHouses'],
            'message'        => "Sačuvano: {$counts['savedCabinets']} ODO, {$counts['savedRoutes']} trasa, {$counts['savedDrops']} drop kablova, {$counts['updatedHouses']} kuća.",
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

    /**
     * Isto ponašanje kao ManagesFtthData::uniqueProjectName() — temp_id brojači
     * (LAB-ODO-001, K-1, ...) kreću ispočetka svaki preview() poziv, pa bi ponovno
     * snimanje plana bez ovoga dupliciralo nazive unutar istog projekta.
     */
    private function uniqueName(string $model, int $projectId, string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'Naziv';
        }

        if (! $model::query()->where('project_id', $projectId)->where('name', $base)->exists()) {
            return $base;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! $model::query()->where('project_id', $projectId)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        abort(422, 'Nije moguće generisati jedinstven naziv.');
    }
}

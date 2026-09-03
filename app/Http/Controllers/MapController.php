<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\Cabinet;
use App\Models\House;
use App\Models\MapDraft;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Services\MapDataService;
use App\Services\RouteGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class MapController extends Controller
{
    use ManagesFtthData;

    public function map(Request $request, MapDataService $mapDataService): View
    {
        $requestedProjectId = (int) $request->input('project');
        $projectId = $requestedProjectId > 0 && Project::whereKey($requestedProjectId)->exists()
            ? $requestedProjectId
            : 0;
        // Keep the picker empty and fast, but embed one selected project's data
        // in the first response so its lines render without a second request.
        $context = $projectId > 0 ? $mapDataService->build($projectId) : null;

        return view('ftth.map', [
            'projects' => Project::orderBy('name')->get(),
            'activeProjectId' => $projectId ?: null,
            'odfsForSelect' => $context['odfs_for_select'] ?? [],
            'cabinetsForSelect' => $context['cabinets_for_select'] ?? [],
            'mapData' => $context['data'] ?? $this->emptyMapData(),
            'mapDataUrl' => null,
        ]);
    }

    public function data(Project $project, MapDataService $mapDataService): JsonResponse
    {
        return response()->json($mapDataService->build($project->id)['data'])
            ->header('Cache-Control', 'private, no-store');
    }

    private function emptyMapData(): array
    {
        return array_fill_keys(['drafts', 'odfs', 'cabinets', 'houses', 'routes', 'gis_segments', 'gis_restricted_areas', 'appendix_items'], []);
    }

    public function storePlan(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'plan' => ['required', 'json', 'max:10485760'],
        ]);

        $plan = json_decode($data['plan'], true);
        Validator::make(['plan' => $plan], ['plan' => ['required', 'array']])->validate();
        Validator::make($plan ?? [], [
            'odfs' => ['nullable', 'array', 'max:500'],
            'odfs.*.lat' => $this->latitudeRules(true),
            'odfs.*.lng' => $this->longitudeRules(true),
            'odfs.*.name' => ['nullable', 'string', 'max:120'],
            'odfs.*.address' => ['nullable', 'string', 'max:255'],
            'odfs.*.fiber_capacity' => ['nullable', 'integer', 'min:1', 'max:1152'],
            'odfs.*.port_count' => ['nullable', 'integer', 'min:1', 'max:1152'],
            'cabinets' => ['nullable', 'array', 'max:5000'],
            'cabinets.*.lat' => $this->latitudeRules(true),
            'cabinets.*.lng' => $this->longitudeRules(true),
            'cabinets.*.name' => ['nullable', 'string', 'max:120'],
            'cabinets.*.address' => ['nullable', 'string', 'max:255'],
            'cabinets.*.splitter_count' => ['nullable', 'integer', 'min:1', 'max:3'],
            'cabinets.*.ports_per_splitter' => ['nullable', 'integer', 'min:1', 'max:4'],
            'houses' => ['nullable', 'array', 'max:20000'],
            'houses.*.lat' => $this->latitudeRules(true),
            'houses.*.lng' => $this->longitudeRules(true),
            'houses.*.label' => ['nullable', 'string', 'max:120'],
            'houses.*.address' => ['nullable', 'string', 'max:255'],
            'routes' => ['nullable', 'array', 'max:10000'],
            'routes.*.route_type' => ['nullable', 'in:trench,backbone,feeder,distribution,drop'],
            'routes.*.odf_id' => ['nullable', 'integer', 'exists:odfs,id'],
            'routes.*.cabinet_id' => ['nullable', 'integer', 'exists:cabinets,id'],
            'routes.*.from_type' => ['nullable', 'in:odf,cabinet'],
            'routes.*.from_id' => ['nullable', 'integer'],
            'routes.*.to_type' => ['nullable', 'in:cabinet'],
            'routes.*.to_id' => ['nullable', 'integer', 'exists:cabinets,id'],
            'routes.*.path' => ['nullable', 'array', 'max:10000'],
            'routes.*.path.*' => ['array', 'size:2'],
            'routes.*.path.*.0' => $this->latitudeRules(true),
            'routes.*.path.*.1' => $this->longitudeRules(true),
            'routes.*.trench_group' => ['nullable', 'string', 'max:80'],
            'routes.*.counts_as_trench' => ['nullable', 'boolean'],
            'routes.*.trench_length_m' => ['nullable', 'integer', 'min:0'],
            'appendix_items' => ['nullable', 'array', 'max:10000'],
            'appendix_items.*.type' => ['required', 'in:manhole,boring_fi_130'],
            'appendix_items.*.lat' => $this->latitudeRules(true),
            'appendix_items.*.lng' => $this->longitudeRules(true),
            'appendix_items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'appendix_items.*.length_m' => ['nullable', 'numeric', 'min:0'],
            'appendix_items.*.angle_deg' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'appendix_items.*.width_m' => ['nullable', 'numeric', 'min:0'],
            'appendix_items.*.note' => ['nullable', 'max:255'],
        ])->validate();

        $projectId = (int) $data['project_id'];

        $projectOdfIds = Odf::where('project_id', $projectId)->pluck('id')->flip()->all();
        $projectCabinetIds = Cabinet::where('project_id', $projectId)->pluck('id')->flip()->all();

        $resolveOdfId = function (?int $id) use (&$projectOdfIds): ?int {
            if (! $id) {
                return null;
            }
            abort_if(! isset($projectOdfIds[$id]), 422, "ODF #{$id} nije validan za ovaj projekat.");

            return $id;
        };
        $resolveCabinetId = function (?int $id) use (&$projectCabinetIds): ?int {
            if (! $id) {
                return null;
            }
            abort_if(! isset($projectCabinetIds[$id]), 422, "ODO #{$id} nije validan za ovaj projekat.");

            return $id;
        };

        $createdOdfs = [];
        $createdCabinets = [];
        $createdHouses = collect();
        $createdSecondaryBranches = collect();

        DB::transaction(function () use ($plan, $projectId, $resolveOdfId, $resolveCabinetId, &$projectOdfIds, &$projectCabinetIds, &$createdOdfs, &$createdCabinets, &$createdHouses, &$createdSecondaryBranches): void {
            foreach (($plan['odfs'] ?? []) as $index => $odf) {
                // Re-saving a plan must not spawn a second ODF at the same spot.
                // Reuse an existing ODF at the identical position instead of
                // creating a duplicate (guards against multi-save duplication).
                $existingOdf = Odf::where('project_id', $projectId)
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->get()
                    ->first(fn (Odf $candidate) => abs((float) $candidate->latitude - (float) $odf['lat']) < 1e-6
                        && abs((float) $candidate->longitude - (float) $odf['lng']) < 1e-6);

                $createdOdfs[$index] = $existingOdf ?? Odf::create([
                    'project_id' => $projectId,
                    'name' => $odf['name'] ?? 'ODF-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'address' => $odf['address'] ?? 'Sa mape',
                    'fiber_capacity' => $odf['fiber_capacity'] ?? 144,
                    'port_count' => $odf['port_count'] ?? 48,
                    'latitude' => $odf['lat'],
                    'longitude' => $odf['lng'],
                ]);
                $projectOdfIds[$createdOdfs[$index]->id] = $createdOdfs[$index]->id;
            }

            foreach (($plan['cabinets'] ?? []) as $index => $cabinet) {
                $odfId = null;
                if (isset($cabinet['odf_index'], $createdOdfs[$cabinet['odf_index']])) {
                    $odfId = $createdOdfs[$cabinet['odf_index']]->id;
                } elseif (! empty($cabinet['odf_id'])) {
                    $odfId = $resolveOdfId((int) $cabinet['odf_id']);
                }

                $cabinetName = $cabinet['name'] ?? $this->nextFtthCabinetNameForProject($projectId);

                $createdCabinets[$index] = Cabinet::create([
                    'project_id' => $projectId,
                    'odf_id' => $odfId,
                    'name' => $this->uniqueProjectName(Cabinet::class, $projectId, $cabinetName),
                    'address' => $cabinet['address'] ?? 'Sa mape',
                    'splitter_count' => $cabinet['splitter_count'] ?? 3,
                    'ports_per_splitter' => $cabinet['ports_per_splitter'] ?? 4,
                    'latitude' => $cabinet['lat'],
                    'longitude' => $cabinet['lng'],
                ]);
                $projectCabinetIds[$createdCabinets[$index]->id] = $createdCabinets[$index]->id;
            }

            foreach (($plan['houses'] ?? []) as $index => $house) {
                $createdHouses->push(House::create([
                    'project_id' => $projectId,
                    'cabinet_id' => isset($house['cabinet_index'], $createdCabinets[$house['cabinet_index']]) ? $createdCabinets[$house['cabinet_index']]->id : null,
                    'label' => $house['label'] ?? 'K-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'address' => $house['address'] ?? null,
                    'latitude' => $house['lat'],
                    'longitude' => $house['lng'],
                    'status' => 'planned',
                ]));
            }

            $routeSignature = static function (?array $path, ?string $type): ?string {
                if (! $path || count($path) < 2) {
                    return null;
                }

                $normalized = array_map(static fn ($point) => [round((float) $point[0], 6), round((float) $point[1], 6)], $path);
                // Direction-agnostic: a route drawn A→B is the same as B→A, so the
                // signature uses whichever orientation sorts first.
                $reversed = array_reverse($normalized);
                $canonical = json_encode($normalized) <= json_encode($reversed) ? $normalized : $reversed;

                // Type is part of the key: a cable and a trench legitimately
                // share a path (cable laid inside the trench), so only a same-type
                // route on the same geometry counts as a duplicate.
                return md5(($type ?? '').'|'.json_encode($canonical));
            };
            $existingRouteSignatures = NetworkRoute::query()
                ->where('project_id', $projectId)
                ->whereNotNull('path')
                ->get(['route_type', 'path'])
                ->reduce(function (array $carry, NetworkRoute $existing) use ($routeSignature): array {
                    if ($signature = $routeSignature($existing->path, $existing->route_type)) {
                        $carry[$signature] = true;
                    }

                    return $carry;
                }, []);

            foreach (($plan['routes'] ?? []) as $index => $route) {
                $routeType = $route['route_type'] ?? 'distribution';

                // Re-saving a plan must not clone a route that already exists at
                // the same geometry and type — skip a drawn route already stored.
                $routeSig = $routeSignature($route['path'] ?? null, $routeType);
                if ($routeSig && isset($existingRouteSignatures[$routeSig])) {
                    continue;
                }
                $routeOdfId = isset($route['odf_index'], $createdOdfs[$route['odf_index']])
                    ? $createdOdfs[$route['odf_index']]->id
                    : $resolveOdfId(isset($route['odf_id']) ? (int) $route['odf_id'] : null);
                $fromType = $route['from_type'] ?? ($routeOdfId ? 'odf' : null);
                $fromId = $route['from_id'] ?? ($fromType === 'odf' ? $routeOdfId : null);

                if ($fromType === 'odf' && $fromId) {
                    $fromId = $resolveOdfId((int) $fromId);
                    $routeOdfId = $fromId;
                } elseif ($fromType === 'cabinet' && $fromId) {
                    $fromId = $resolveCabinetId((int) $fromId);
                }
                $routeCabinetId = isset($route['cabinet_index'], $createdCabinets[$route['cabinet_index']])
                    ? $createdCabinets[$route['cabinet_index']]->id
                    : $resolveCabinetId(isset($route['cabinet_id']) ? (int) $route['cabinet_id'] : null);
                $routeToId = isset($route['to_id'])
                    ? $resolveCabinetId((int) $route['to_id'])
                    : $routeCabinetId;

                $routeName = $route['name'] ?? $this->nextRouteNameForProject($projectId, $routeType);

                // A stale browser draft can still contain a route immediately after
                // that same saved route was edited. Treat its stable project/type/name
                // identity as an update; otherwise every click on "Sačuvaj na mapi"
                // creates Name-2, Name-3... when only one vertex changed.
                $existingNamedRoute = NetworkRoute::query()
                    ->where('project_id', $projectId)
                    ->where('route_type', $routeType)
                    ->where('name', $routeName)
                    ->first();
                if ($existingNamedRoute) {
                    $existingNamedRoute->update([
                        'odf_id' => $routeOdfId,
                        'cabinet_id' => $routeCabinetId,
                        'from_type' => $fromType,
                        'from_id' => $fromId,
                        'to_type' => $route['to_type'] ?? ($routeToId ? 'cabinet' : null),
                        'to_id' => $routeToId,
                        'installation_type' => $route['installation_type'] ?? 'underground',
                        'trench_group' => $route['trench_group'] ?? null,
                        'counts_as_trench' => $routeType === 'trench',
                        'trench_length_m' => null,
                        'duct_length_m' => $route['duct_length_m'] ?? 0,
                        'fiber_length_m' => $routeType === 'trench' ? 0 : ($route['fiber_length_m'] ?? 0),
                        'fiber_count' => $routeType === 'trench' ? null : ($route['fiber_count'] ?? 12),
                        'microduct_count' => $routeType === 'trench' ? 0 : ($route['microduct_count'] ?? 1),
                        'microduct_type' => $routeType === 'trench' ? null : ($route['microduct_type'] ?? '14/10'),
                        'path' => $route['path'] ?? null,
                    ]);
                    if ($routeSig) {
                        $existingRouteSignatures[$routeSig] = true;
                    }

                    continue;
                }

                $createdRoute = NetworkRoute::create([
                    'project_id' => $projectId,
                    'odf_id' => $routeOdfId,
                    'cabinet_id' => $routeCabinetId,
                    'from_type' => $fromType,
                    'from_id' => $fromId,
                    'to_type' => $route['to_type'] ?? ($routeToId ? 'cabinet' : null),
                    'to_id' => $routeToId,
                    'name' => $this->uniqueProjectName(NetworkRoute::class, $projectId, $routeName),
                    'route_type' => $routeType,
                    'installation_type' => $route['installation_type'] ?? 'underground',
                    'trench_group' => $route['trench_group'] ?? null,
                    'counts_as_trench' => $routeType === 'trench',
                    'trench_length_m' => null,
                    'duct_length_m' => $route['duct_length_m'] ?? 0,
                    'fiber_length_m' => $routeType === 'trench' ? 0 : ($route['fiber_length_m'] ?? 0),
                    'fiber_count' => $route['fiber_count'] ?? 12,
                    'microduct_count' => $routeType === 'trench' ? 0 : ($route['microduct_count'] ?? 1),
                    'microduct_type' => $routeType === 'trench' ? null : ($route['microduct_type'] ?? '14/10'),
                    'status' => 'planned',
                    'path' => $route['path'] ?? null,
                ]);
                $createdBranch = $this->createBranchForRoute($createdRoute);
                if ($createdBranch && $createdBranch->type === 'secondary' && count($createdRoute->path ?? []) > 1) {
                    $createdSecondaryBranches->push(['branch' => $createdBranch, 'route' => $createdRoute]);
                }
                if ($routeSig) {
                    $existingRouteSignatures[$routeSig] = true;
                }
            }

            foreach (($plan['appendix_items'] ?? []) as $item) {
                ProjectAppendixItem::create([
                    'project_id' => $projectId,
                    'type' => $item['type'],
                    'quantity' => $item['type'] === 'boring_fi_130' ? ($item['length_m'] ?? $item['quantity'] ?? 1) : ($item['quantity'] ?? 1),
                    'unit' => $item['type'] === 'boring_fi_130' ? 'metara' : 'KOMADA',
                    'latitude' => $item['lat'],
                    'longitude' => $item['lng'],
                    'length_m' => $item['length_m'] ?? null,
                    'angle_deg' => $item['angle_deg'] ?? null,
                    'width_m' => $item['width_m'] ?? null,
                    'note' => $item['note'] ?? null,
                ]);
            }

            $this->assignCreatedHousesToDraftBranches($createdHouses, $createdSecondaryBranches);

            MapDraft::where('project_id', $projectId)->delete();
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Cijeli plan sa mape je sacuvan.',
                'created' => [
                    'odfs' => count($createdOdfs),
                    'cabinets' => count($createdCabinets),
                    'houses' => count($plan['houses'] ?? []),
                    'routes' => count($plan['routes'] ?? []),
                    'appendix_items' => count($plan['appendix_items'] ?? []),
                ],
            ]);
        }

        return back()->with('success', 'Cijeli plan sa mape je sacuvan.');
    }

    public function storeDraft(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'draft' => ['required', 'json', 'max:10485760'],
        ]);

        $payload = json_decode($data['draft'], true);
        Validator::make(['draft' => $payload], [
            'draft' => ['required', 'array'],
            'draft.odfs' => ['nullable', 'array', 'max:500'],
            'draft.cabinets' => ['nullable', 'array', 'max:5000'],
            'draft.houses' => ['nullable', 'array', 'max:20000'],
            'draft.routes' => ['nullable', 'array', 'max:10000'],
            'draft.routes.*.path' => ['nullable', 'array', 'max:10000'],
            'draft.appendix_items' => ['nullable', 'array', 'max:10000'],
        ])->validate();

        $draft = MapDraft::updateOrCreate(
            ['project_id' => $data['project_id']],
            ['payload' => $payload]
        );

        return response()->json([
            'message' => 'Nacrt projekta je sacuvan.',
            'updated_at' => $draft->updated_at?->format('Y-m-d H:i'),
        ]);
    }

    public function autoRoute(Request $request, RouteGraphService $graph)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'from_lat' => $this->latitudeRules(true),
            'from_lng' => $this->longitudeRules(true),
            'to_lat' => $this->latitudeRules(true),
            'to_lng' => $this->longitudeRules(true),
        ]);

        $result = $graph->shortestPath(
            (int) $data['project_id'],
            [(float) $data['from_lat'], (float) $data['from_lng']],
            [(float) $data['to_lat'], (float) $data['to_lng']],
        );

        if (! $result) {
            return response()->json([
                'message' => 'Nema dovoljno postojece trase/GIS grafa za automatsku rutu.',
            ], 422);
        }

        return response()->json($result);
    }
}

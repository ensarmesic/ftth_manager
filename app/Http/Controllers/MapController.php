<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\MapDraft;
use App\Models\Material;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\ProjectAppendixItem;
use App\Models\Subscriber;
use App\Services\FtthIntelligenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Http\Controllers\Concerns\ManagesFtthData;

class MapController extends Controller
{
    use ManagesFtthData;

    public function map(): View
    {
        $odfs = Odf::with('project')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        $cabinets = Cabinet::with(['project', 'odf', 'parentCabinet', 'branch'])
            ->withCount(['houses', 'subscribers'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        $routes = NetworkRoute::with(['project', 'odf', 'cabinet'])
            ->where(function ($query) {
                $query->whereNotNull('path')
                    ->orWhere(function ($linkedQuery) {
                        $linkedQuery
                            ->whereHas('odf', fn ($odfQuery) => $odfQuery->whereNotNull('latitude')->whereNotNull('longitude'))
                            ->whereHas('cabinet', fn ($cabinetQuery) => $cabinetQuery->whereNotNull('latitude')->whereNotNull('longitude'));
                    });
            })
            ->get();

        $houses = House::with(['project', 'cabinet'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();
        $appendixItems = ProjectAppendixItem::with('project')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        return view('ftth.map', [
            'projects' => Project::orderBy('name')->get(),
            'odfsForSelect' => Odf::with('project')->orderBy('name')->get(),
            'cabinetsForSelect' => Cabinet::with('project')->orderBy('name')->get(),
            'odfs' => $odfs,
            'cabinets' => $cabinets,
            'houses' => $houses,
            'routes' => $routes,
            'appendixItems' => $appendixItems,
            'mapData' => [
                'drafts' => MapDraft::with('project')->latest()->get()->map(fn (MapDraft $draft) => [
                    'project_id' => $draft->project_id,
                    'project' => $draft->project->name,
                    'payload' => $draft->payload,
                    'updated_at' => $draft->updated_at?->format('Y-m-d H:i'),
                ]),
                'odfs' => $odfs->map(fn (Odf $odf) => [
                    'id' => $odf->id,
                    'project_id' => $odf->project_id,
                    'name' => $odf->name,
                    'project' => $odf->project->name,
                    'address' => $odf->address,
                    'fiber_capacity' => $odf->fiber_capacity,
                    'port_count' => $odf->port_count,
                    'lat' => (float) $odf->latitude,
                    'lng' => (float) $odf->longitude,
                ]),
                'cabinets' => $cabinets->map(fn (Cabinet $cabinet) => [
                    'id' => $cabinet->id,
                    'project_id' => $cabinet->project_id,
                    'name' => $cabinet->name,
                    'project' => $cabinet->project->name,
                    'odf_id' => $cabinet->odf_id,
                    'parent_cabinet_id' => $cabinet->parent_cabinet_id,
                    'branch_id' => $cabinet->branch_id,
                    'branch_name' => $cabinet->branch?->name,
                    'branch_code' => $cabinet->branch?->code,
                    'odf' => $cabinet->odf->name ?? 'Nije povezano',
                    'parent_cabinet' => $cabinet->parentCabinet?->name,
                    'fed_from' => $cabinet->parentCabinet?->name ?? ($cabinet->odf->name ?? 'Nije povezano'),
                    'address' => $cabinet->address,
                    'capacity' => $cabinet->capacity,
                    'used_ports' => $cabinet->houses_count,
                    'free_ports' => max($cabinet->capacity - $cabinet->houses_count, 0),
                    'utilization' => (int) round($cabinet->houses_count / max($cabinet->capacity, 1) * 100),
                    'lat' => (float) $cabinet->latitude,
                    'lng' => (float) $cabinet->longitude,
                ]),
                'houses' => $houses->map(fn (House $house) => [
                    'id' => $house->id,
                    'project_id' => $house->project_id,
                    'label' => $house->label,
                    'project' => $house->project->name,
                    'cabinet' => $house->cabinet->name ?? 'Nije dodijeljeno',
                    'cabinet_id' => $house->cabinet_id,
                    'address' => $house->address,
                    'status' => $house->status,
                    'lat' => (float) $house->latitude,
                    'lng' => (float) $house->longitude,
                ]),
                'routes' => $routes->map(fn (NetworkRoute $route) => [
                    'id' => $route->id,
                    'name' => $route->name,
                    'project' => $route->project->name,
                    'type' => $route->route_type,
                    'installation_type' => $route->installation_type,
                    'trench_group' => $route->trench_group,
                    'counts_as_trench' => (bool) $route->counts_as_trench,
                    'trench_length_m' => $route->trench_length_m,
                    'microduct_type' => $route->microduct_type,
                    'fiber_count' => $route->fiber_count,
                    'duct_length_m' => $route->duct_length_m,
                    'fiber_length_m' => $route->fiber_length_m,
                    'from' => $this->routeStartLabel($route),
                    'to' => $route->cabinet?->name ?? '-',
                    'length' => $route->duct_length_m,
                    'microduct' => $route->microduct_type,
                    'fibers' => $route->fiber_count,
                    'odf_id' => $route->odf_id,
                    'cabinet_id' => $route->cabinet_id,
                    'from_type' => $route->from_type,
                    'from_id' => $route->from_id,
                    'to_type' => $route->to_type,
                    'to_id' => $route->to_id,
                    'occupancy' => $this->ftthIntelligence->routeOccupancy($route),
                    'status' => $route->status,
                    'note' => $route->note,
                    'path' => $route->path ?: ($route->odf && $route->cabinet ? [
                        [(float) $route->odf->latitude, (float) $route->odf->longitude],
                        [(float) $route->cabinet->latitude, (float) $route->cabinet->longitude],
                    ] : []),
                ]),
                'appendix_items' => $appendixItems->map(fn (ProjectAppendixItem $item) => [
                    'id' => $item->id,
                    'project_id' => $item->project_id,
                    'project' => $item->project->name,
                    'type' => $item->type,
                    'label' => $item->type === 'manhole' ? 'Saht' : 'FI 130',
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'note' => $item->note,
                    'lat' => (float) $item->latitude,
                    'lng' => (float) $item->longitude,
                    'length_m' => $item->length_m === null ? null : (float) $item->length_m,
                    'angle_deg' => $item->angle_deg === null ? null : (float) $item->angle_deg,
                    'width_m' => $item->width_m === null ? null : (float) $item->width_m,
                ]),
            ],
        ]);
    }

    public function storePlan(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'plan' => ['required', 'json'],
        ]);

        $plan = json_decode($data['plan'], true);
        Validator::make($plan ?? [], [
            'odfs' => ['nullable', 'array'],
            'odfs.*.lat' => $this->latitudeRules(true),
            'odfs.*.lng' => $this->longitudeRules(true),
            'cabinets' => ['nullable', 'array'],
            'cabinets.*.lat' => $this->latitudeRules(true),
            'cabinets.*.lng' => $this->longitudeRules(true),
            'houses' => ['nullable', 'array'],
            'houses.*.lat' => $this->latitudeRules(true),
            'houses.*.lng' => $this->longitudeRules(true),
            'routes' => ['nullable', 'array'],
            'routes.*.route_type' => ['nullable', 'in:trench,backbone,feeder,distribution,drop'],
            'routes.*.odf_id' => ['nullable', 'integer', 'exists:odfs,id'],
            'routes.*.cabinet_id' => ['nullable', 'integer', 'exists:cabinets,id'],
            'routes.*.from_type' => ['nullable', 'in:odf,cabinet'],
            'routes.*.from_id' => ['nullable', 'integer'],
            'routes.*.to_type' => ['nullable', 'in:cabinet'],
            'routes.*.to_id' => ['nullable', 'integer', 'exists:cabinets,id'],
            'routes.*.path' => ['nullable', 'array'],
            'routes.*.path.*' => ['array', 'size:2'],
            'routes.*.path.*.0' => $this->latitudeRules(true),
            'routes.*.path.*.1' => $this->longitudeRules(true),
            'routes.*.trench_group' => ['nullable', 'string', 'max:80'],
            'routes.*.counts_as_trench' => ['nullable', 'boolean'],
            'routes.*.trench_length_m' => ['nullable', 'integer', 'min:0'],
            'appendix_items' => ['nullable', 'array'],
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
        $createdOdfs = [];
        $createdCabinets = [];
        $createdHouses = collect();
        $createdSecondaryBranches = collect();

        DB::transaction(function () use ($plan, $projectId, &$createdOdfs, &$createdCabinets, &$createdHouses, &$createdSecondaryBranches): void {
            foreach (($plan['odfs'] ?? []) as $index => $odf) {
                $createdOdfs[$index] = Odf::create([
                    'project_id' => $projectId,
                    'name' => $odf['name'] ?? 'ODF-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'address' => $odf['address'] ?? 'Sa mape',
                    'fiber_capacity' => $odf['fiber_capacity'] ?? 144,
                    'port_count' => $odf['port_count'] ?? 48,
                    'latitude' => $odf['lat'],
                    'longitude' => $odf['lng'],
                ]);
            }

            foreach (($plan['cabinets'] ?? []) as $index => $cabinet) {
                $odfId = null;
                if (isset($cabinet['odf_index'], $createdOdfs[$cabinet['odf_index']])) {
                    $odfId = $createdOdfs[$cabinet['odf_index']]->id;
                } elseif (! empty($cabinet['odf_id'])) {
                    $odfId = Odf::query()
                        ->where('project_id', $projectId)
                        ->findOrFail($cabinet['odf_id'])
                        ->id;
                }

                $cabinetName = $cabinet['name'] ?? $this->nextFtthCabinetNameForProject($projectId);

                $createdCabinets[$index] = Cabinet::create([
                    'project_id' => $projectId,
                    'odf_id' => $odfId,
                    'name' => $this->uniqueProjectName(Cabinet::class, $projectId, $cabinetName),
                    'address' => $cabinet['address'] ?? 'Sa mape',
                    'splitter_count' => $cabinet['splitter_count'] ?? 3,
                    'ports_per_splitter' => 4,
                    'latitude' => $cabinet['lat'],
                    'longitude' => $cabinet['lng'],
                ]);
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

            foreach (($plan['routes'] ?? []) as $index => $route) {
                $routeType = $route['route_type'] ?? 'distribution';
                $routeOdfId = isset($route['odf_index'], $createdOdfs[$route['odf_index']])
                    ? $createdOdfs[$route['odf_index']]->id
                    : ($route['odf_id'] ?? null);
                $fromType = $route['from_type'] ?? ($routeOdfId ? 'odf' : null);
                $fromId = $route['from_id'] ?? ($fromType === 'odf' ? $routeOdfId : null);

                if ($routeOdfId) {
                    $routeOdfId = Odf::query()
                        ->where('project_id', $projectId)
                        ->findOrFail($routeOdfId)
                        ->id;
                }
                if ($fromType === 'odf' && $fromId) {
                    $fromId = Odf::query()->where('project_id', $projectId)->findOrFail($fromId)->id;
                    $routeOdfId = $fromId;
                }
                if ($fromType === 'cabinet' && $fromId) {
                    $fromId = Cabinet::query()->where('project_id', $projectId)->findOrFail($fromId)->id;
                }
                $routeCabinetId = isset($route['cabinet_index'], $createdCabinets[$route['cabinet_index']])
                    ? $createdCabinets[$route['cabinet_index']]->id
                    : ($route['cabinet_id'] ?? null);
                if ($routeCabinetId) {
                    $routeCabinetId = Cabinet::query()->where('project_id', $projectId)->findOrFail($routeCabinetId)->id;
                }
                $routeToId = $route['to_id'] ?? $routeCabinetId;
                if ($routeToId) {
                    $routeToId = Cabinet::query()->where('project_id', $projectId)->findOrFail($routeToId)->id;
                }

                $routeName = $route['name'] ?? $this->nextRouteNameForProject($projectId, $routeType);

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
            'draft' => ['required', 'json'],
        ]);

        $draft = MapDraft::updateOrCreate(
            ['project_id' => $data['project_id']],
            ['payload' => json_decode($data['draft'], true)]
        );

        return response()->json([
            'message' => 'Nacrt projekta je sacuvan.',
            'updated_at' => $draft->updated_at?->format('Y-m-d H:i'),
        ]);
    }
}


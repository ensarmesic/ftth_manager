<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Material;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\Subscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Http\Controllers\Concerns\ManagesFtthData;

class ProjectController extends Controller
{
    use ManagesFtthData;

    public function dashboard(): View
    {
        $projects = Project::withCount(['odfs', 'cabinets', 'subscribers', 'houses', 'routes'])->latest()->get();
        $activeProject = $projects->first();
        $projectQuery = fn ($query) => $activeProject ? $query->where('project_id', $activeProject->id) : $query->whereRaw('1 = 0');
        $odfs = $projectQuery(Odf::query())->withCount('cabinets')->get();
        $cabinets = $projectQuery(Cabinet::query())->with(['project', 'odf', 'parentCabinet'])->withCount(['subscribers', 'houses'])->orderBy('name')->get();
        $houses = $projectQuery(House::query())->with('cabinet')->get();
        $subscribers = $projectQuery(Subscriber::query())->with('cabinet')->latest()->take(5)->get();
        $routes = $projectQuery(NetworkRoute::query())->with(['odf', 'cabinet', 'fromCabinet'])->latest()->get();
        $validationItems = $activeProject ? collect($this->ftthIntelligence->validateProject($activeProject)) : collect();
        $issues = $validationItems->reject(fn (array $item) => $item['level'] === 'ok')->values();
        $materialSummary = $activeProject ? $this->ftthIntelligence->materialSummary($activeProject) : [];


        return view('ftth.dashboard', [
            'projects' => $projects,
            'activeProject' => $activeProject,
            'odfs' => $odfs,
            'cabinets' => $cabinets,
            'houses' => $houses,
            'subscribers' => $subscribers,
            'routes' => $routes,
            'issues' => $issues,
            'mapData' => [
                'odfs' => $odfs->map(fn (Odf $odf) => ['id' => $odf->id, 'name' => $odf->name, 'address' => $odf->address, 'ports' => $odf->port_count, 'fibers' => $odf->fiber_capacity, 'cabinets' => $odf->cabinets_count, 'lat' => (float) $odf->latitude, 'lng' => (float) $odf->longitude]),
                'cabinets' => $cabinets->map(fn (Cabinet $cabinet) => ['id' => $cabinet->id, 'name' => $cabinet->name, 'address' => $cabinet->address, 'odf' => $cabinet->odf->name ?? 'Nije povezano', 'parent_cabinet_id' => $cabinet->parent_cabinet_id, 'parent_cabinet' => $cabinet->parentCabinet?->name, 'fed_from' => $cabinet->parentCabinet?->name ?? ($cabinet->odf->name ?? 'Nije povezano'), 'used' => $cabinet->houses_count, 'capacity' => $cabinet->capacity, 'splitters' => $cabinet->splitter_count, 'lat' => (float) $cabinet->latitude, 'lng' => (float) $cabinet->longitude]),
                'houses' => $houses->map(fn (House $house) => ['id' => $house->id, 'name' => $house->label, 'address' => $house->address, 'cabinet_id' => $house->cabinet_id, 'cabinet' => $house->cabinet->name ?? 'Nije povezano', 'status' => $house->status, 'lat' => (float) $house->latitude, 'lng' => (float) $house->longitude]),
                'routes' => $routes->map(fn (NetworkRoute $route) => ['id' => $route->id, 'name' => $route->name, 'from' => $this->routeStartLabel($route), 'to' => $route->cabinet->name ?? '-', 'odf_id' => $route->odf_id, 'from_type' => $route->from_type, 'from_id' => $route->from_id, 'cabinet_id' => $route->cabinet_id, 'type' => $route->route_type, 'length' => $route->duct_length_m, 'microduct' => $route->microduct_type, 'fibers' => $route->fiber_count, 'note' => $route->note, 'path' => $route->path ?: ($route->odf && $route->cabinet ? [[(float) $route->odf->latitude, (float) $route->odf->longitude], [(float) $route->cabinet->latitude, (float) $route->cabinet->longitude]] : [])]),
            ],
            'stats' => [
                'projects' => $projects->count(),
                'odfs' => $projects->sum('odfs_count'),
                'cabinets' => $projects->sum('cabinets_count'),
                'houses' => $projects->sum('houses_count'),
                'subscribers' => $projects->sum('subscribers_count'),
                'duct_m' => NetworkRoute::sum('duct_length_m'),
                'fiber_m' => NetworkRoute::sum('fiber_length_m'),
                'materials_cost' => Material::query()->selectRaw('SUM(planned_quantity * unit_price) as total')->value('total') ?? 0,
                'splitters' => $cabinets->sum('splitter_count'),
                'routes_m' => $routes->sum('duct_length_m'),
                'microduct_14_10' => $routes->where('microduct_type', '14/10')->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count),
                'microduct_10_8' => $routes->where('microduct_type', '10/8')->sum(fn (NetworkRoute $route) => $route->duct_length_m * $route->microduct_count),
                'fiber_4' => $routes->where('fiber_count', 4)->sum('fiber_length_m'),
                'fiber_12' => $routes->where('fiber_count', 12)->sum('fiber_length_m'),
                'fiber_24' => $routes->where('fiber_count', 24)->sum('fiber_length_m'),
                'issues_errors' => $validationItems->where('level', 'error')->count(),
                'issues_warnings' => $validationItems->where('level', 'warning')->count(),
            ],
            'materialSummary' => $materialSummary,
            'validationItems' => $validationItems,
        ]);
    }

    public function projects(): View
    {
        return view('ftth.projects', [
            'projects' => Project::latest()->paginate(12),
            'projectStats' => [
                'total' => Project::count(),
                'active' => Project::where('status', 'active')->count(),
                'planning' => Project::where('status', 'planning')->count(),
                'completed' => Project::where('status', 'completed')->count(),
            ],
        ]);
    }

    public function deleteProject($id)
    {
        Project::findOrFail($id)->delete();

        return back()->with('success', 'Projekat je obrisan.');
    }

    public function updateProject(Request $request, $id): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $project->update($request->validate([
            'name' => ['required', 'max:255'],
            'code' => ['required', 'max:50', 'unique:projects,code,'.$project->id],
            'location' => ['required', 'max:255'],
            'investor' => ['nullable', 'max:255'],
            'status' => ['required', 'in:planning,active,paused,completed'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'max:2000'],
        ]));

        return back()->with('success', 'Projekat je azuriran.');
    }

    public function previewOdoPlan(Request $request, Project $project)
    {
        try {
            return response()->json($this->ftthIntelligence->previewOdoPlan($project, $request->all()));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function confirmOdoPlan(Request $request, Project $project)
    {
        $data = $request->validate([
            'plan' => ['required', 'array'],
            'create_drop_routes' => ['nullable', 'boolean'],
        ]);

        try {
            return response()->json($this->ftthIntelligence->confirmOdoPlan(
                $project,
                $data['plan'],
                $request->boolean('create_drop_routes')
            ), 201);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Plan nije snimljen. Sve izmjene su ponistene.'], 500);
        }
    }

    public function validateProject(Project $project)
    {
        return response()->json([
            'project' => ['id' => $project->id, 'name' => $project->name],
            'items' => $this->ftthIntelligence->validateProject($project),
            'materials' => $this->ftthIntelligence->materialSummary($project),
        ]);
    }

    public function createMissingDropRoutes(Project $project)
    {
        $existingDropHouseIds = array_flip(
            NetworkRoute::where('project_id', $project->id)
                ->where('route_type', 'drop')
                ->where('to_type', 'house')
                ->pluck('to_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        $houses = $project->houses()
            ->with('cabinet')
            ->whereNotNull('cabinet_id')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->reject(fn (House $house) => isset($existingDropHouseIds[$house->id]));

        $routes = DB::transaction(function () use ($project, $houses) {
            return $houses->map(function (House $house) use ($project) {
                $cabinet = $house->cabinet;
                if (! $cabinet || $cabinet->latitude === null || $cabinet->longitude === null) {
                    return null;
                }

                $path = $this->dropPathForHouse($project, $cabinet, $house);
                $length = $this->polylineLength($path);

                return NetworkRoute::create([
                    'project_id' => $project->id,
                    'cabinet_id' => $cabinet->id,
                    'from_type' => 'cabinet',
                    'from_id' => $cabinet->id,
                    'to_type' => 'house',
                    'to_id' => $house->id,
                    'name' => $this->uniqueProjectName(NetworkRoute::class, $project->id, "Drop {$cabinet->name}-{$house->label}"),
                    'route_type' => 'drop',
                    'installation_type' => 'underground',
                    'duct_length_m' => $length,
                    'fiber_length_m' => $length,
                    'fiber_count' => 4,
                    'microduct_count' => 1,
                    'microduct_type' => '10/8',
                    'status' => 'planned',
                    'path' => $path,
                ]);
            })->filter()->values();
        });

        return response()->json([
            'message' => 'Nedostajuce drop trase su kreirane.',
            'created' => $routes->count(),
            'routes' => $routes->map(fn (NetworkRoute $route) => [
                'id' => $route->id,
                'name' => $route->name,
                'type' => $route->route_type,
                'length' => $route->duct_length_m,
                'duct_length_m' => $route->duct_length_m,
                'microduct' => $route->microduct_type,
                'fibers' => $route->fiber_count,
                'path' => $route->path,
                'note' => $route->note,
                'from_type' => $route->from_type,
                'from_id' => $route->from_id,
                'to_type' => $route->to_type,
                'to_id' => $route->to_id,
                'cabinet_id' => $route->cabinet_id,
            ]),
        ]);
    }

    public function exportGeoJson(Project $project)
    {
        $project->load(['odfs', 'cabinets', 'houses', 'routes']);
        $features = collect();

        $project->odfs->whereNotNull('latitude')->whereNotNull('longitude')->each(function (Odf $odf) use ($features): void {
            $features->push($this->geoJsonPoint('odf', $odf->id, $odf->name, (float) $odf->latitude, (float) $odf->longitude, [
                'address' => $odf->address,
                'fiber_capacity' => $odf->fiber_capacity,
                'port_count' => $odf->port_count,
            ]));
        });

        $project->cabinets->whereNotNull('latitude')->whereNotNull('longitude')->each(function (Cabinet $cabinet) use ($features): void {
            $features->push($this->geoJsonPoint('ftth', $cabinet->id, $cabinet->name, (float) $cabinet->latitude, (float) $cabinet->longitude, [
                'odf_id' => $cabinet->odf_id,
                'parent_cabinet_id' => $cabinet->parent_cabinet_id,
                'splitter_count' => $cabinet->splitter_count,
                'capacity' => $cabinet->capacity,
            ]));
        });

        $project->houses->whereNotNull('latitude')->whereNotNull('longitude')->each(function (House $house) use ($features): void {
            $features->push($this->geoJsonPoint('house', $house->id, $house->label, (float) $house->latitude, (float) $house->longitude, [
                'cabinet_id' => $house->cabinet_id,
                'status' => $house->status,
                'address' => $house->address,
            ]));
        });

        $project->routes->filter(fn (NetworkRoute $route) => count($route->path ?? []) > 1)->each(function (NetworkRoute $route) use ($features): void {
            $features->push([
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => collect($route->path)->map(fn (array $point) => [(float) $point[1], (float) $point[0]])->values()->all(),
                ],
                'properties' => [
                    'element_type' => 'route',
                    'id' => $route->id,
                    'name' => $route->name,
                    'route_type' => $route->route_type,
                    'duct_length_m' => $route->duct_length_m,
                    'fiber_length_m' => $route->fiber_length_m,
                    'fiber_count' => $route->fiber_count,
                    'microduct_count' => $route->microduct_count,
                    'microduct_type' => $route->microduct_type,
                ],
            ]);
        });

        $payload = [
            'type' => 'FeatureCollection',
            'name' => $project->code ?: $project->name,
            'features' => $features->values()->all(),
        ];

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$project->code.'-ftth.geojson"',
        ], JSON_UNESCAPED_UNICODE);
    }

    public function exportDxf(Project $project)
    {
        $project->load(['odfs', 'cabinets', 'houses', 'routes']);
        $lines = [
            '0', 'SECTION', '2', 'HEADER', '9', '$ACADVER', '1', 'AC1009', '0', 'ENDSEC',
            '0', 'SECTION', '2', 'ENTITIES',
        ];

        foreach ($project->routes as $route) {
            $path = $route->path ?? [];
            if (count($path) < 2) {
                continue;
            }
            array_push($lines, ...$this->dxfPolyline($path, $this->dxfLayerForRoute($route), $this->dxfColorForRoute($route)));
            $middle = $path[(int) floor((count($path) - 1) / 2)];
            array_push($lines, ...$this->dxfText((float) $middle[1], (float) $middle[0], $route->name, 'FTTH_LABELS', 7, 0.000018));
        }

        foreach ($project->odfs as $odf) {
            if ($odf->latitude === null || $odf->longitude === null) {
                continue;
            }
            array_push($lines, ...$this->dxfPoint((float) $odf->longitude, (float) $odf->latitude, 'FTTH_ODF', 5));
            array_push($lines, ...$this->dxfText((float) $odf->longitude, (float) $odf->latitude, $odf->name, 'FTTH_LABELS', 7));
        }

        foreach ($project->cabinets as $cabinet) {
            if ($cabinet->latitude === null || $cabinet->longitude === null) {
                continue;
            }
            array_push($lines, ...$this->dxfPoint((float) $cabinet->longitude, (float) $cabinet->latitude, 'FTTH_CABINETS', 3));
            array_push($lines, ...$this->dxfText((float) $cabinet->longitude, (float) $cabinet->latitude, $cabinet->name, 'FTTH_LABELS', 7));
        }

        foreach ($project->houses as $house) {
            if ($house->latitude === null || $house->longitude === null) {
                continue;
            }
            array_push($lines, ...$this->dxfPoint((float) $house->longitude, (float) $house->latitude, 'FTTH_HOUSES', 3));
        }

        array_push($lines, '0', 'ENDSEC', '0', 'EOF');

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'application/dxf',
            'Content-Disposition' => 'attachment; filename="'.$project->code.'-ftth.dxf"',
        ]);
    }

    public function printProject(Project $project): View
    {
        $project->load([
            'odfs.cabinets',
            'cabinets.houses',
            'houses.cabinet',
            'routes' => fn ($query) => $query->orderBy('route_type')->orderBy('name'),
            'appendixItems',
        ]);

        return view('ftth.projects.print', [
            'project' => $project,
            'validationItems' => collect($this->ftthIntelligence->validateProject($project)),
            'materials' => $this->ftthIntelligence->materialSummary($project),
        ]);
    }

    private function dropPathForHouse(Project $project, Cabinet $cabinet, House $house): array
    {
        $directPath = [
            [(float) $cabinet->latitude, (float) $cabinet->longitude],
            [(float) $house->latitude, (float) $house->longitude],
        ];

        $route = $project->routes()
            ->whereNotIn('route_type', ['trench', 'drop'])
            ->whereNotNull('path')
            ->get()
            ->filter(fn (NetworkRoute $route) => count($route->path ?? []) >= 2)
            ->sortBy(fn (NetworkRoute $route) => $this->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $route)['distance_m'])
            ->first();

        if (! $route) {
            return $directPath;
        }

        $cabinetProjection = $this->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $route);
        $houseProjection = $this->projectPointToRoute((float) $house->latitude, (float) $house->longitude, $route);
        if ($cabinetProjection['distance_m'] > 35 || $houseProjection['distance_m'] > 90) {
            return $directPath;
        }

        return $this->compactPath(array_merge(
            [[(float) $cabinet->latitude, (float) $cabinet->longitude], [$cabinetProjection['lat'], $cabinetProjection['lng']]],
            array_slice($this->routePathBetween($route, $cabinetProjection, $houseProjection), 1),
            [[(float) $house->latitude, (float) $house->longitude]]
        ));
    }

    private function geoJsonPoint(string $type, int $id, string $name, float $lat, float $lng, array $properties = []): array
    {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$lng, $lat],
            ],
            'properties' => array_merge([
                'element_type' => $type,
                'id' => $id,
                'name' => $name,
            ], $properties),
        ];
    }

    private function dxfPolyline(array $path, string $layer, int $color): array
    {
        $lines = ['0', 'POLYLINE', '8', $layer, '62', (string) $color, '66', '1', '70', '0'];
        foreach ($path as $point) {
            $lines = array_merge($lines, [
                '0', 'VERTEX', '8', $layer,
                '10', (string) ((float) $point[1]),
                '20', (string) ((float) $point[0]),
                '30', '0',
            ]);
        }

        return array_merge($lines, ['0', 'SEQEND']);
    }

    private function dxfPoint(float $x, float $y, string $layer, int $color): array
    {
        return ['0', 'POINT', '8', $layer, '62', (string) $color, '10', (string) $x, '20', (string) $y, '30', '0'];
    }

    private function dxfText(float $x, float $y, string $text, string $layer, int $color, float $height = 0.000028): array
    {
        return [
            '0', 'TEXT', '8', $layer, '62', (string) $color,
            '10', (string) $x, '20', (string) $y, '30', '0',
            '40', (string) $height,
            '1', $this->dxfSafeText($text),
        ];
    }

    private function dxfLayerForRoute(NetworkRoute $route): string
    {
        return match ($route->route_type) {
            'trench' => 'FTTH_TRENCH',
            'drop' => 'FTTH_DROP',
            'backbone', 'feeder' => 'FTTH_PRIMARY',
            default => 'FTTH_SECONDARY',
        };
    }

    private function dxfColorForRoute(NetworkRoute $route): int
    {
        return match ($route->route_type) {
            'trench' => 7,
            'drop' => 30,
            'backbone', 'feeder' => 5,
            default => 3,
        };
    }

    private function dxfSafeText(string $text): string
    {
        return str_replace(["\r", "\n"], ' ', $text);
    }

    public function projectCheck(): View
    {
        $projects = Project::with([
            'odfs',
            'cabinets' => fn ($query) => $query->withCount('houses'),
            'houses',
            'routes',
        ])->withCount(['odfs', 'cabinets', 'houses', 'routes'])->orderBy('name')->get();

        return view('ftth.project-check', ['projects' => $projects]);
    }

    public function settings(): View
    {
        return view('ftth.settings');
    }

    public function storeProject(Request $request)
    {
        if ($request->boolean('quick_create')) {
            $request->merge([
                'code' => $this->nextProjectCode($request->input('name')),
                'location' => $request->input('location') ?: 'Sa mape',
                'status' => 'planning',
            ]);
        }

        $project = Project::create($request->validate([
            'name' => ['required', 'max:255'],
            'code' => ['required', 'max:50', 'unique:projects,code'],
            'location' => ['required', 'max:255'],
            'investor' => ['nullable', 'max:255'],
            'status' => ['required', 'in:planning,active,paused,completed'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'max:2000'],
        ]));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Projekat je kreiran.',
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                ],
            ]);
        }

        return back()->with('success', 'Projekat je kreiran.');
    }
}


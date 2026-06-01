<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\MapDraft;
use App\Models\Material;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\Subscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FtthController extends Controller
{
    public function dashboard(): View
    {
        $projects = Project::withCount(['odfs', 'cabinets', 'subscribers', 'houses', 'routes'])->latest()->get();
        $cabinets = Cabinet::with('project')->withCount('subscribers')->orderBy('name')->get();

        return view('ftth.dashboard', [
            'projects' => $projects,
            'cabinets' => $cabinets,
            'stats' => [
                'projects' => Project::count(),
                'odfs' => Odf::count(),
                'cabinets' => Cabinet::count(),
                'houses' => House::count(),
                'subscribers' => Subscriber::count(),
                'duct_m' => NetworkRoute::sum('duct_length_m'),
                'fiber_m' => NetworkRoute::sum('fiber_length_m'),
                'materials_cost' => Material::query()->selectRaw('SUM(planned_quantity * unit_price) as total')->value('total') ?? 0,
            ],
        ]);
    }

    public function projects(): View
    {
        return view('ftth.projects', ['projects' => Project::latest()->paginate(12)]);
    }

    public function deleteProject($id)
    {
        Project::findOrFail($id)->delete();
        return back()->with('success', 'Projekat je obrisan.');
    }

    public function map(): View
    {
        $odfs = Odf::with('project')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        $cabinets = Cabinet::with(['project', 'odf'])
            ->withCount('subscribers')
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

        return view('ftth.map', [
            'projects' => Project::orderBy('name')->get(),
            'odfsForSelect' => Odf::with('project')->orderBy('name')->get(),
            'cabinetsForSelect' => Cabinet::with('project')->orderBy('name')->get(),
            'odfs' => $odfs,
            'cabinets' => $cabinets,
            'houses' => $houses,
            'routes' => $routes,
            'mapData' => [
                'drafts' => MapDraft::with('project')->latest()->get()->map(fn (MapDraft $draft) => [
                    'project_id' => $draft->project_id,
                    'project' => $draft->project->name,
                    'payload' => $draft->payload,
                    'updated_at' => $draft->updated_at?->format('Y-m-d H:i'),
                ]),
                'odfs' => $odfs->map(fn (Odf $odf) => [
                    'id' => $odf->id,
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
                    'name' => $cabinet->name,
                    'project' => $cabinet->project->name,
                    'odf' => $cabinet->odf->name ?? 'Nije povezano',
                    'address' => $cabinet->address,
                    'capacity' => $cabinet->capacity,
                    'used_ports' => $cabinet->used_ports,
                    'free_ports' => max($cabinet->capacity - $cabinet->used_ports, 0),
                    'utilization' => $cabinet->utilization,
                    'lat' => (float) $cabinet->latitude,
                    'lng' => (float) $cabinet->longitude,
                ]),
                'houses' => $houses->map(fn (House $house) => [
                    'id' => $house->id,
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
                    'microduct_type' => $route->microduct_type,
                    'fiber_count' => $route->fiber_count,
                    'duct_length_m' => $route->duct_length_m,
                    'fiber_length_m' => $route->fiber_length_m,
                    'cabinet_id' => $route->cabinet_id,
                    'status' => $route->status,
                    'path' => $route->path ?: ($route->odf && $route->cabinet ? [
                        [(float) $route->odf->latitude, (float) $route->odf->longitude],
                        [(float) $route->cabinet->latitude, (float) $route->cabinet->longitude],
                    ] : []),
                ]),
            ],
        ]);
    }

    public function reports(): View
    {
        $projects = Project::withCount(['odfs', 'cabinets', 'subscribers', 'houses', 'routes'])
            ->with(['materials', 'routes', 'cabinets' => fn ($query) => $query->withCount('subscribers')])
            ->orderBy('name')
            ->get();

        return view('ftth.reports', [
            'projects' => $projects,
            'totals' => [
                'projects' => Project::count(),
                'subscribers' => Subscriber::count(),
                'houses' => House::count(),
                'cabinets' => Cabinet::count(),
                'free_ports' => Cabinet::withCount('subscribers')->get()->sum(fn (Cabinet $cabinet) => max($cabinet->capacity - $cabinet->subscribers_count, 0)),
                'duct' => NetworkRoute::sum('duct_length_m'),
                'fiber' => NetworkRoute::sum('fiber_length_m'),
                'materials_cost' => Material::query()->selectRaw('SUM(planned_quantity * unit_price) as total')->value('total') ?? 0,
            ],
        ]);
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

    private function nextProjectCode(?string $name): string
    {
        $base = Str::upper(Str::slug($name ?: 'projekat'));
        $base = Str::limit($base ?: 'PROJEKAT', 42, '');
        $code = $base;
        $suffix = 2;

        while (Project::where('code', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }

    public function odfs(): View
    {
        return view('ftth.odfs', [
            'odfs' => Odf::with('project')->latest()->paginate(12),
            'projects' => Project::orderBy('name')->get(),
        ]);
    }

    public function storeOdf(Request $request): RedirectResponse
    {
        Odf::create($request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'name' => ['required', 'max:255'],
            'address' => ['required', 'max:255'],
            'fiber_capacity' => ['required', 'integer', 'min:1'],
            'port_count' => ['required', 'integer', 'min:1'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'max:2000'],
        ]));

        return back()->with('success', 'ODF lokacija je evidentirana.');
    }

    public function deleteOdf($id)
    {
        Odf::findOrFail($id)->delete();
        return back()->with('success', 'ODF lokacija je obrisana.');
    }

    public function updateOdfPosition(Request $request, $id)
    {
        return $this->updatePosition($request, Odf::findOrFail($id));
    }

    public function cabinets(): View
    {
        return view('ftth.cabinets', [
            'cabinets' => Cabinet::with(['project', 'odf'])->withCount('subscribers')->latest()->paginate(12),
            'projects' => Project::orderBy('name')->get(),
            'odfs' => Odf::with('project')->orderBy('name')->get(),
        ]);
    }

    public function storeCabinet(Request $request): RedirectResponse
    {
        Cabinet::create($request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'odf_id' => ['nullable', 'exists:odfs,id'],
            'name' => ['required', 'max:255'],
            'address' => ['required', 'max:255'],
            'splitter_count' => ['required', 'integer', 'min:1', 'max:3'],
            'ports_per_splitter' => ['required', 'integer', 'min:1', 'max:4'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]));

        return back()->with('success', 'Ormaric je dodat.');
    }

    public function deleteCabinet($id)
    {
        Cabinet::findOrFail($id)->delete();
        return back()->with('success', 'Ormaric je obrisan.');
    }

    public function updateCabinetPosition(Request $request, $id)
    {
        return $this->updatePosition($request, Cabinet::findOrFail($id));
    }

    public function storeHouse(Request $request): RedirectResponse
    {
        House::create($request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'label' => ['required', 'max:255'],
            'address' => ['nullable', 'max:255'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'status' => ['required', 'in:planned,connected,cancelled'],
        ]));

        return back()->with('success', 'Kuca/prikljucak je evidentiran.');
    }

    public function deleteHouse($id)
    {
        House::findOrFail($id)->delete();
        return back()->with('success', 'Kuca je obrisana.');
    }

    public function updateHousePosition(Request $request, $id)
    {
        return $this->updatePosition($request, House::findOrFail($id));
    }

    private function updatePosition(Request $request, Model $element)
    {
        $position = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $element->update($position);

        return response()->json([
            'message' => 'Nova pozicija je sacuvana.',
            'latitude' => (float) $element->latitude,
            'longitude' => (float) $element->longitude,
        ]);
    }

    public function storePlan(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'plan' => ['required', 'json'],
        ]);

        $plan = json_decode($data['plan'], true);
        $projectId = (int) $data['project_id'];
        $createdOdfs = [];
        $createdCabinets = [];

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
                $odfId = $cabinet['odf_id'];
            }

            $createdCabinets[$index] = Cabinet::create([
                'project_id' => $projectId,
                'odf_id' => $odfId,
                'name' => $cabinet['name'] ?? 'FTTH-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'address' => $cabinet['address'] ?? 'Sa mape',
                'splitter_count' => $cabinet['splitter_count'] ?? 3,
                'ports_per_splitter' => 4,
                'latitude' => $cabinet['lat'],
                'longitude' => $cabinet['lng'],
            ]);
        }

        foreach (($plan['houses'] ?? []) as $index => $house) {
            House::create([
                'project_id' => $projectId,
                'cabinet_id' => isset($house['cabinet_index'], $createdCabinets[$house['cabinet_index']]) ? $createdCabinets[$house['cabinet_index']]->id : null,
                'label' => $house['label'] ?? 'K-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'address' => $house['address'] ?? null,
                'latitude' => $house['lat'],
                'longitude' => $house['lng'],
                'status' => 'planned',
            ]);
        }

        foreach (($plan['routes'] ?? []) as $index => $route) {
            NetworkRoute::create([
                'project_id' => $projectId,
                'odf_id' => isset($route['odf_index'], $createdOdfs[$route['odf_index']]) ? $createdOdfs[$route['odf_index']]->id : null,
                'cabinet_id' => isset($route['cabinet_index'], $createdCabinets[$route['cabinet_index']]) ? $createdCabinets[$route['cabinet_index']]->id : null,
                'name' => $route['name'] ?? 'Trasa '.($index + 1),
                'route_type' => $route['route_type'] ?? 'distribution',
                'installation_type' => $route['installation_type'] ?? 'underground',
                'duct_length_m' => $route['duct_length_m'] ?? 0,
                'fiber_length_m' => $route['fiber_length_m'] ?? 0,
                'fiber_count' => $route['fiber_count'] ?? 12,
                'microduct_count' => $route['microduct_count'] ?? 1,
                'microduct_type' => $route['microduct_type'] ?? '14/10',
                'status' => 'planned',
                'path' => $route['path'] ?? null,
            ]);
        }

        MapDraft::where('project_id', $projectId)->delete();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Cijeli plan sa mape je sacuvan.',
                'created' => [
                    'odfs' => count($createdOdfs),
                    'cabinets' => count($createdCabinets),
                    'houses' => count($plan['houses'] ?? []),
                    'routes' => count($plan['routes'] ?? []),
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

    public function subscribers(): View
    {
        return view('ftth.subscribers', [
            'subscribers' => Subscriber::with(['project', 'cabinet'])->latest()->paginate(12),
            'projects' => Project::orderBy('name')->get(),
            'cabinets' => Cabinet::withCount('subscribers')->orderBy('name')->get(),
        ]);
    }

    public function storeSubscriber(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'name' => ['required', 'max:255'],
            'address' => ['required', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'max:50'],
            'service_status' => ['required', 'in:planned,connected,in_service,cancelled'],
            'splitter_no' => ['nullable', 'integer', 'min:1', 'max:3'],
            'port_no' => ['nullable', 'integer', 'min:1', 'max:4'],
            'connected_at' => ['nullable', 'date'],
        ]);

        if (! empty($data['cabinet_id'])) {
            $cabinet = Cabinet::withCount('subscribers')->findOrFail($data['cabinet_id']);
            if ($cabinet->subscribers_count >= $cabinet->capacity) {
                return back()->withErrors(['cabinet_id' => 'Odabrani ormaric je popunjen. Potrebno je planirati novi ormaric.'])->withInput();
            }
        }

        Subscriber::create($data);

        return back()->with('success', 'Korisnik je evidentiran.');
    }

    public function deleteSubscriber($id)
    {
        Subscriber::findOrFail($id)->delete();
        return back()->with('success', 'Korisnik je obrisan.');
    }

    public function routes(): View
    {
        $routes = NetworkRoute::with(['project', 'odf', 'cabinet'])->latest()->paginate(12);
        $totalDuct = NetworkRoute::sum('duct_length_m');
        $effectiveMicroduct = NetworkRoute::query()->selectRaw('SUM(duct_length_m * microduct_count) as total')->value('total') ?? 0;
        $totalFiber = NetworkRoute::sum('fiber_length_m');

        return view('ftth.routes', [
            'routes' => $routes,
            'projects' => Project::orderBy('name')->get(),
            'odfs' => Odf::orderBy('name')->get(),
            'cabinets' => Cabinet::orderBy('name')->get(),
            'routeStats' => [
                'duct' => $totalDuct,
                'effective_microduct' => $effectiveMicroduct,
                'microduct_with_reserve' => ceil($effectiveMicroduct * 1.1),
                'fiber' => $totalFiber,
                'fiber_with_reserve' => ceil($totalFiber * 1.1),
                'planned_cabinets' => Cabinet::count(),
                'planned_splitters' => Cabinet::sum('splitter_count'),
            ],
        ]);
    }

    public function storeRoute(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'odf_id' => ['nullable', 'exists:odfs,id'],
            'cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'name' => ['required', 'max:255'],
            'route_type' => ['required', 'in:feeder,distribution,drop'],
            'installation_type' => ['required', 'in:aerial,underground'],
            'duct_length_m' => ['required', 'integer', 'min:0'],
            'fiber_length_m' => ['required', 'integer', 'min:0'],
            'fiber_count' => ['required', 'integer', 'in:4,12,24,48'],
            'microduct_count' => ['required', 'integer', 'min:1'],
            'microduct_type' => ['required', 'in:14/10,10/8'],
            'status' => ['required', 'in:planned,in_progress,built'],
            'path' => ['nullable', 'json'],
        ]);

        if (! empty($data['path'])) {
            $data['path'] = json_decode($data['path'], true);
        }

        NetworkRoute::create($data);

        return back()->with('success', 'Trasa je evidentirana.');
    }

    public function deleteRoute($id)
    {
        NetworkRoute::findOrFail($id)->delete();
        return back()->with('success', 'Trasa je obrisana.');
    }

    public function importDxf(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'dxf' => ['required', 'file', 'max:10240'],
        ]);

        $entities = $this->parseDxfPolylines($data['dxf']->get());
        if (! count($entities)) {
            return back()->withErrors(['dxf' => 'DXF nema podrzane LINE, LWPOLYLINE ili POLYLINE entitete.']);
        }

        foreach ($entities as $index => $points) {
            $length = $this->polylineLength($points);
            NetworkRoute::create([
                'project_id' => $data['project_id'],
                'name' => 'DXF trasa '.($index + 1),
                'route_type' => 'distribution',
                'installation_type' => 'underground',
                'duct_length_m' => $length,
                'fiber_length_m' => $length,
                'fiber_count' => 12,
                'microduct_count' => 1,
                'microduct_type' => '14/10',
                'status' => 'planned',
                'path' => $points,
            ]);
        }

        return back()->with('success', 'DXF importovan: '.count($entities).' trasa.');
    }

    private function parseDxfPolylines(string $contents): array
    {
        $lines = preg_split('/\R/', $contents);
        $pairs = [];
        for ($i = 0; $i + 1 < count($lines); $i += 2) {
            $pairs[] = [trim($lines[$i]), trim($lines[$i + 1])];
        }

        $entities = [];
        $currentType = null;
        $current = [];
        $point = [];

        $flushPoint = function () use (&$current, &$point): void {
            if (isset($point['x'], $point['y'])) $current[] = [(float) $point['y'], (float) $point['x']];
            $point = [];
        };
        $flushEntity = function () use (&$entities, &$current, &$point, $flushPoint): void {
            $flushPoint();
            if (count($current) >= 2) $entities[] = $current;
            $current = [];
        };

        foreach ($pairs as [$code, $value]) {
            if ($code === '0') {
                if (in_array($value, ['LINE', 'LWPOLYLINE', 'POLYLINE'], true)) {
                    $flushEntity();
                    $currentType = $value;
                    continue;
                }
                if ($value === 'VERTEX' && $currentType === 'POLYLINE') {
                    $flushPoint();
                    continue;
                }
                if ($currentType === 'LINE' || $currentType === 'LWPOLYLINE' || ($currentType === 'POLYLINE' && $value === 'SEQEND')) {
                    $flushEntity();
                    $currentType = null;
                }
                continue;
            }

            if (! $currentType) continue;
            if ($code === '10') {
                if (isset($point['x'])) $flushPoint();
                $point['x'] = $value;
            }
            if ($code === '20') $point['y'] = $value;
            if ($currentType === 'LINE' && $code === '11') {
                $flushPoint();
                $point['x'] = $value;
            }
            if ($currentType === 'LINE' && $code === '21') $point['y'] = $value;
        }
        $flushEntity();

        return $entities;
    }

    private function polylineLength(array $points): int
    {
        $distance = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            [$lat1, $lng1] = $points[$i - 1];
            [$lat2, $lng2] = $points[$i];
            $earth = 6371000;
            $latDelta = deg2rad($lat2 - $lat1);
            $lngDelta = deg2rad($lng2 - $lng1);
            $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
            $distance += $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
        }

        return (int) round($distance);
    }

    public function calculateMaterials(Project $project)
    {
        $routes = $project->routes;
        $specs = [
            'Mikrocijev 14/10' => ['unit' => 'm', 'quantity' => 0],
            'Mikrocijev 10/8' => ['unit' => 'm', 'quantity' => 0],
            'Opticki kabl 4 niti' => ['unit' => 'm', 'quantity' => 0],
            'Opticki kabl 12 niti' => ['unit' => 'm', 'quantity' => 0],
            'Opticki kabl 24 niti' => ['unit' => 'm', 'quantity' => 0],
            'Opticki kabl 48 niti' => ['unit' => 'm', 'quantity' => 0],
            'ODO ormaric' => ['unit' => 'kom', 'quantity' => $project->cabinets()->count()],
            'Splitter 1:4' => ['unit' => 'kom', 'quantity' => $project->cabinets()->sum('splitter_count')],
            'ODF' => ['unit' => 'kom', 'quantity' => $project->odfs()->count()],
        ];

        foreach ($routes as $route) {
            $ductKey = 'Mikrocijev '.$route->microduct_type;
            $fiberKey = 'Opticki kabl '.$route->fiber_count.' niti';
            $specs[$ductKey]['quantity'] += $route->duct_length_m * $route->microduct_count;
            $specs[$fiberKey]['quantity'] += $route->fiber_length_m;
        }

        DB::transaction(function () use ($project, $specs) {
            foreach ($specs as $name => $spec) {
                Material::updateOrCreate(
                    ['project_id' => $project->id, 'name' => $name],
                    ['unit' => $spec['unit'], 'planned_quantity' => $spec['quantity'], 'used_quantity' => 0, 'unit_price' => 0]
                );
            }
        });

        return back()->with('success', 'Materijali su obracunati iz spremljenih trasa i kapaciteta.');
    }

    public function materials(): View
    {
        return view('ftth.materials', [
            'materials' => Material::with('project')->latest()->paginate(12),
            'projects' => Project::orderBy('name')->get(),
        ]);
    }

    public function storeMaterial(Request $request): RedirectResponse
    {
        Material::create($request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'name' => ['required', 'max:255'],
            'unit' => ['required', 'max:30'],
            'planned_quantity' => ['required', 'numeric', 'min:0'],
            'used_quantity' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
        ]));

        return back()->with('success', 'Materijal je dodat.');
    }

    public function deleteMaterial($id)
    {
        Material::findOrFail($id)->delete();
        return back()->with('success', 'Materijal je obrisan.');
    }

    public function storeSuggestedCabinets(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'cabinets' => ['required', 'array', 'min:1'],
            'cabinets.*.name' => ['required', 'max:255'],
            'cabinets.*.latitude' => ['required', 'numeric'],
            'cabinets.*.longitude' => ['required', 'numeric'],
            'cabinets.*.splitter_count' => ['required', 'integer', 'min:1', 'max:3'],
            'cabinets.*.odf_id' => ['nullable', 'integer', 'exists:odfs,id'],
        ]);

        $projectId = $data['project_id'];
        $createdCount = 0;

        foreach ($data['cabinets'] as $cabinet) {
            Cabinet::create([
                'project_id' => $projectId,
                'odf_id' => $cabinet['odf_id'] ?? null,
                'name' => $cabinet['name'],
                'address' => 'Sa mape - ' . $cabinet['latitude'] . ',' . $cabinet['longitude'],
                'splitter_count' => $cabinet['splitter_count'],
                'ports_per_splitter' => 4,
                'latitude' => $cabinet['latitude'],
                'longitude' => $cabinet['longitude'],
            ]);
            $createdCount++;
        }

        return response()->json([
            'message' => "Kreirano {$createdCount} ormarića.",
            'created' => $createdCount,
        ]);
    }
}

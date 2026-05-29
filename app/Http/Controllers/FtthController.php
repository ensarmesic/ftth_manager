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
            ->whereHas('odf', fn ($query) => $query->whereNotNull('latitude')->whereNotNull('longitude'))
            ->whereHas('cabinet', fn ($query) => $query->whereNotNull('latitude')->whereNotNull('longitude'))
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
                    'duct_length_m' => $route->duct_length_m,
                    'fiber_length_m' => $route->fiber_length_m,
                    'status' => $route->status,
                    'path' => $route->path ?: [
                        [(float) $route->odf->latitude, (float) $route->odf->longitude],
                        [(float) $route->cabinet->latitude, (float) $route->cabinet->longitude],
                    ],
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

    public function storeProject(Request $request): RedirectResponse
    {
        Project::create($request->validate([
            'name' => ['required', 'max:255'],
            'code' => ['required', 'max:50', 'unique:projects,code'],
            'location' => ['required', 'max:255'],
            'status' => ['required', 'in:planning,active,paused,completed'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'max:2000'],
        ]));

        return back()->with('success', 'Projekat je kreiran.');
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
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]));

        return back()->with('success', 'ODF lokacija je evidentirana.');
    }

    public function deleteOdf($id)
    {
        Odf::findOrFail($id)->delete();
        return back()->with('success', 'ODF lokacija je obrisana.');
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
                'duct_length_m' => $route['duct_length_m'] ?? 0,
                'fiber_length_m' => $route['fiber_length_m'] ?? 0,
                'microduct_count' => $route['microduct_count'] ?? 1,
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
            'duct_length_m' => ['required', 'integer', 'min:0'],
            'fiber_length_m' => ['required', 'integer', 'min:0'],
            'microduct_count' => ['required', 'integer', 'min:1'],
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

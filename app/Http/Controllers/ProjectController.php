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
        $routes = $projectQuery(NetworkRoute::query())->with(['odf', 'cabinet'])->latest()->get();
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
                'cabinets' => $cabinets->map(fn (Cabinet $cabinet) => ['id' => $cabinet->id, 'name' => $cabinet->name, 'address' => $cabinet->address, 'odf' => $cabinet->odf->name ?? 'Nije povezano', 'parent_cabinet_id' => $cabinet->parent_cabinet_id, 'parent_cabinet' => $cabinet->parentCabinet?->name, 'fed_from' => $cabinet->parentCabinet?->name ?? ($cabinet->odf->name ?? 'Nije povezano'), 'used' => $cabinet->houses_count, 'capacity' => 12, 'splitters' => $cabinet->splitter_count, 'lat' => (float) $cabinet->latitude, 'lng' => (float) $cabinet->longitude]),
                'houses' => $houses->map(fn (House $house) => ['id' => $house->id, 'name' => $house->label, 'address' => $house->address, 'cabinet_id' => $house->cabinet_id, 'cabinet' => $house->cabinet->name ?? 'Nije povezano', 'status' => $house->status, 'lat' => (float) $house->latitude, 'lng' => (float) $house->longitude]),
                'routes' => $routes->map(fn (NetworkRoute $route) => ['id' => $route->id, 'name' => $route->name, 'from' => $this->routeStartLabel($route), 'to' => $route->cabinet->name ?? '-', 'odf_id' => $route->odf_id, 'from_type' => $route->from_type, 'from_id' => $route->from_id, 'cabinet_id' => $route->cabinet_id, 'type' => $route->route_type, 'length' => $route->duct_length_m, 'microduct' => $route->microduct_type, 'fibers' => $route->fiber_count, 'note' => $route->note, 'path' => $route->path ?: ($route->odf && $route->cabinet ? [[(float) $route->odf->latitude, (float) $route->odf->longitude], [(float) $route->cabinet->latitude, (float) $route->cabinet->longitude]] : [])]),
            ],
            'stats' => [
                'projects' => Project::count(),
                'odfs' => Odf::count(),
                'cabinets' => Cabinet::count(),
                'houses' => House::count(),
                'subscribers' => Subscriber::count(),
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


<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectManagementController extends Controller
{
    use ManagesFtthData;

    public function index(): View
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

    public function show(Project $project): View
    {
        $project->load([
            'odfs.cabinets.houses',
            'cabinets' => fn ($query) => $query->withCount('houses')->with(['odf', 'houses', 'branch']),
            'houses.cabinet',
            'routes',
            'branches' => fn ($query) => $query->withCount('cabinets')->orderBy('sort_order'),
            'materials',
        ]);

        $validationItems = collect($this->ftthIntelligence->validateProject($project));
        $materials = $this->ftthIntelligence->materialSummary($project);
        $cableRoutes = $project->routes->where('route_type', '!=', 'trench');
        $trenchRoutes = $project->routes->where('route_type', 'trench');
        $odfCapacity = $project->odfs->map(fn ($odf) => [
            'odf' => $odf,
            'total' => $odf->fiber_capacity,
            'used' => $odf->cabinets->filter(fn ($cabinet) => $cabinet->parent_cabinet_id === null)->sum('splitter_count'),
        ]);

        return view('ftth.projects.show', compact('project', 'validationItems', 'materials', 'cableRoutes', 'trenchRoutes', 'odfCapacity'));
    }

    public function store(Request $request)
    {
        if ($request->boolean('quick_create')) {
            $request->merge([
                'code' => $this->nextProjectCode($request->input('name')),
                'location' => $request->input('location') ?: 'Sa mape',
                'status' => 'planning',
            ]);
        }

        $project = Project::create($request->validate($this->rules()));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Projekat je kreiran.',
                'project' => ['id' => $project->id, 'name' => $project->name],
            ]);
        }

        return back()->with('success', 'Projekat je kreiran.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $project->update($request->validate($this->rules($project)));

        return back()->with('success', 'Projekat je ažuriran.');
    }

    public function destroy(int $id)
    {
        Project::findOrFail($id)->delete();

        return back()->with('success', 'Projekat je obrisan.');
    }

    private function rules(?Project $project = null): array
    {
        return [
            'name' => ['required', 'max:255'],
            'code' => ['required', 'max:50', 'unique:projects,code'.($project ? ','.$project->id : '')],
            'location' => ['required', 'max:255'],
            'investor' => ['nullable', 'max:255'],
            'status' => ['required', 'in:planning,active,paused,completed'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'max:2000'],
        ];
    }
}

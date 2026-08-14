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

    public function index(Request $request): View
    {
        $projects = Project::query()->withCount(['odfs', 'cabinets', 'houses', 'routes'])
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($search) => $search
                ->where('name', 'like', '%'.$request->string('q')->trim().'%')
                ->orWhere('code', 'like', '%'.$request->string('q')->trim().'%')
                ->orWhere('location', 'like', '%'.$request->string('q')->trim().'%')
                ->orWhere('investor', 'like', '%'.$request->string('q')->trim().'%')))
            ->latest()->paginate(12)->withQueryString();

        return view('ftth.projects', [
            'projects' => $projects,
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

        $validationItems = collect($this->projectValidation->validateProject($project));
        $materials = $this->projectMaterials->summary($project);
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

        if ($request->input('next') === 'map') {
            return redirect()->route('map.dashboard', ['project' => $project->id])
                ->with('success', 'Projekat je kreiran. Sada postavi ODF i mrežne elemente na mapu.');
        }

        return back()->with('success', 'Projekat je kreiran.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $project = Project::findOrFail($id);
        if ($project->fiber_schema_locked) {
            foreach (['fiber_layout', 'fiber_color_standard', 'fiber_reserve_per_tube', 'fiber_budget_limit_db', 'pon_profile', 'fiber_attenuation_1310_db_km', 'fiber_attenuation_1490_db_km', 'fiber_attenuation_1577_db_km', 'connector_loss_db', 'connector_count', 'splice_allowance_db', 'planned_splice_count', 'engineering_margin_db', 'additional_passive_loss_db', 'power_budget_confirmed', 'olt_tx_power_dbm', 'onu_tx_power_dbm', 'onu_rx_sensitivity_dbm', 'olt_rx_sensitivity_dbm'] as $field) {
                abort_if($request->has($field) && (string) $request->input($field) !== (string) $project->{$field}, 423, 'Fiber postavke su zaključane odobrenom šemom.');
            }
        }
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
            'fiber_layout' => ['nullable', 'in:6x24,12x12,4x24,2x24'],
            'fiber_color_standard' => ['nullable', 'in:telcordia,din_vde'],
            'fiber_reserve_per_tube' => ['nullable', 'integer', 'min:0', 'max:12'],
            'fiber_budget_limit_db' => ['nullable', 'numeric', 'min:10', 'max:60'],
            'pon_profile' => ['nullable', 'in:gpon_b_plus,gpon_c_plus,gpon_d,xgs_n1,xgs_n2,xgs_e1,xgs_e2'],
            'fiber_attenuation_1310_db_km' => ['nullable', 'numeric', 'min:0.1', 'max:2'],
            'fiber_attenuation_1490_db_km' => ['nullable', 'numeric', 'min:0.1', 'max:2'],
            'fiber_attenuation_1577_db_km' => ['nullable', 'numeric', 'min:0.1', 'max:2'],
            'connector_loss_db' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'connector_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'splice_allowance_db' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'planned_splice_count' => ['nullable', 'integer', 'min:0', 'max:200'],
            'engineering_margin_db' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'additional_passive_loss_db' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'power_budget_confirmed' => ['nullable', 'boolean'],
            'olt_tx_power_dbm' => ['nullable', 'required_if:power_budget_confirmed,1', 'numeric', 'min:-10', 'max:20'],
            'onu_tx_power_dbm' => ['nullable', 'required_if:power_budget_confirmed,1', 'numeric', 'min:-10', 'max:20'],
            'onu_rx_sensitivity_dbm' => ['nullable', 'required_if:power_budget_confirmed,1', 'numeric', 'min:-50', 'max:0'],
            'olt_rx_sensitivity_dbm' => ['nullable', 'required_if:power_budget_confirmed,1', 'numeric', 'min:-50', 'max:0'],
        ];
    }
}

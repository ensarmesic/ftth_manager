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

class MaterialController extends Controller
{
    use ManagesFtthData;

    public function materials(): View
    {
        $materialTotals = Material::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(planned_quantity * unit_price), 0) as planned_cost')
            ->selectRaw('COALESCE(SUM(used_quantity * unit_price), 0) as used_cost')
            ->first();

        return view('ftth.materials', [
            'materials' => Material::with('project')->latest()->paginate(12),
            'projects' => Project::orderBy('name')->get(),
            'materialStats' => [
                'total' => (int) $materialTotals->total,
                'planned_cost' => (float) $materialTotals->planned_cost,
                'used_cost' => (float) $materialTotals->used_cost,
                'projects' => Project::count(),
            ],
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

    public function updateMaterial(Request $request, $id): RedirectResponse
    {
        $material = Material::findOrFail($id);
        $material->update($request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'name' => ['required', 'max:255'],
            'unit' => ['required', 'max:30'],
            'planned_quantity' => ['required', 'numeric', 'min:0'],
            'used_quantity' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
        ]));

        return back()->with('success', 'Materijal je azuriran.');
    }

    public function deleteMaterial($id)
    {
        Material::findOrFail($id)->delete();

        return back()->with('success', 'Materijal je obrisan.');
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
            if ($route->route_type === 'trench') {
                continue;
            }
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
}


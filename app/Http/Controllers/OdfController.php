<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OdfController extends Controller
{
    use ManagesFtthData;

    public function odfs(Request $request): View
    {
        $projectId = Project::query()->whereKey($request->integer('project'))->value('id');
        $odfScope = fn () => Odf::query()->when($projectId, fn ($query) => $query->where('project_id', $projectId));

        return view('ftth.odfs', [
            'odfs' => Odf::with('project')->when($projectId, fn ($query) => $query->where('project_id', $projectId))->when($request->filled('q'), fn ($query) => $query->where(fn ($search) => $search
                ->where('name', 'like', '%'.$request->string('q')->trim().'%')->orWhere('address', 'like', '%'.$request->string('q')->trim().'%')
                ->orWhereHas('project', fn ($project) => $project->where('name', 'like', '%'.$request->string('q')->trim().'%'))))
                ->latest()->paginate(12)->withQueryString(),
            'projects' => Project::orderBy('name')->get(),
            'odfStats' => [
                'total' => $odfScope()->count(),
                'ports' => $odfScope()->sum('port_count'),
                'fibers' => $odfScope()->sum('fiber_capacity'),
                'projects' => $projectId ? 1 : Project::count(),
            ],
            'selectedProject' => $projectId ? Project::find($projectId) : null,
            'projectContext' => $this->projectWorkspaceContext($projectId),
        ]);
    }

    public function storeOdf(Request $request)
    {
        $odf = Odf::create($request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'name' => ['required', 'max:255'],
            'address' => ['required', 'max:255'],
            'fiber_capacity' => ['required', 'integer', 'min:1'],
            'port_count' => ['required', 'integer', 'min:1'],
            'latitude' => $this->latitudeRules(),
            'longitude' => $this->longitudeRules(),
            'notes' => ['nullable', 'max:2000'],
        ]));

        if ($request->expectsJson()) {
            return response()->json(['message' => 'ODF lokacija je evidentirana.', 'odf' => [
                'id' => $odf->id, 'name' => $odf->name, 'address' => $odf->address, 'ports' => $odf->port_count,
                'fibers' => $odf->fiber_capacity, 'cabinets' => 0, 'lat' => (float) $odf->latitude, 'lng' => (float) $odf->longitude,
            ]], 201);
        }

        return back()->with('success', 'ODF lokacija je evidentirana.');
    }

    public function deleteOdf($id)
    {
        $odf = Odf::findOrFail($id);
        NetworkRoute::query()->where('project_id', $odf->project_id)
            ->where(fn ($query) => $query->where('odf_id', $odf->id)
                ->orWhere(fn ($routeQuery) => $routeQuery->where('from_type', 'odf')->where('from_id', $odf->id)))
            ->get()->each(function (NetworkRoute $route) use ($odf): void {
                $updates = [];
                if ((int) $route->odf_id === (int) $odf->id) {
                    $updates['odf_id'] = null;
                }
                if ($route->from_type === 'odf' && (int) $route->from_id === (int) $odf->id) {
                    $updates['from_type'] = null;
                    $updates['from_id'] = null;
                }
                if ($updates) {
                    $route->update($updates);
                }
            });
        $odf->delete();
        if (request()->expectsJson()) {
            return response()->json(['message' => 'ODF lokacija je obrisana.']);
        }

        return back()->with('success', 'ODF lokacija je obrisana.');
    }

    public function updateOdf(Request $request, $id): RedirectResponse|JsonResponse
    {
        $odf = Odf::findOrFail($id);
        $odf->update($request->validate([
            'project_id' => ['required', Rule::in([$odf->project_id])],
            'name' => ['required', 'max:255'],
            'address' => ['required', 'max:255'],
            'fiber_capacity' => ['required', 'integer', 'min:1'],
            'port_count' => ['required', 'integer', 'min:1'],
            'latitude' => $this->latitudeRules(),
            'longitude' => $this->longitudeRules(),
            'notes' => ['nullable', 'max:2000'],
        ]));

        if ($request->expectsJson()) {
            return response()->json(['message' => 'ODF lokacija je ažurirana.', 'item' => $odf->fresh()]);
        }

        return back()->with('success', 'ODF lokacija je ažurirana.');
    }

    public function updateOdfPosition(Request $request, $id)
    {
        return $this->updatePosition($request, Odf::findOrFail($id));
    }
}

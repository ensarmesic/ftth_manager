<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\Cabinet;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BranchController extends Controller
{
    use ManagesFtthData;

    public function branches(Request $request): View
    {
        $projectId = Project::query()->whereKey($request->integer('project'))->value('id');
        $branchScope = fn () => NetworkBranch::query()->when($projectId, fn ($query) => $query->where('project_id', $projectId));
        $branchStats = [
            'total' => $branchScope()->whereNotIn('type', ['rov'])->count(),
            'primary' => $branchScope()->where('type', 'primary')->count(),
            'secondary' => $branchScope()->where('type', 'secondary')->count(),
            'cabinets' => Cabinet::whereNotNull('branch_id')->when($projectId, fn ($query) => $query->where('project_id', $projectId))->count(),
            'rov' => $branchScope()->where('type', 'rov')->count(),
        ];

        return view('ftth.branches', [
            'branches' => NetworkBranch::with(['project', 'odf', 'parentBranch', 'route'])->withCount('cabinets')
                ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
                ->when($request->filled('q'), fn ($query) => $query->where(fn ($search) => $search
                    ->where('name', 'like', '%'.$request->string('q')->trim().'%')->orWhere('code', 'like', '%'.$request->string('q')->trim().'%')
                    ->orWhereHas('project', fn ($project) => $project->where('name', 'like', '%'.$request->string('q')->trim().'%'))))
                ->orderBy('project_id')->orderBy('sort_order')->paginate(30)->withQueryString(),
            'projects' => Project::orderBy('name')->get(),
            'odfs' => Odf::with('project')->when($projectId, fn ($query) => $query->where('project_id', $projectId))->orderBy('name')->get(),
            'parentBranches' => NetworkBranch::with('project')->when($projectId, fn ($query) => $query->where('project_id', $projectId))->orderBy('name')->get(),
            'routes' => NetworkRoute::with('project')->whereIn('route_type', ['backbone', 'feeder', 'distribution'])->when($projectId, fn ($query) => $query->where('project_id', $projectId))->orderBy('name')->get(),
            'branchStats' => $branchStats,
            'selectedProject' => $projectId ? Project::find($projectId) : null,
            'projectContext' => $this->projectWorkspaceContext($projectId),
        ]);
    }

    public function storeBranch(Request $request)
    {
        NetworkBranch::create($this->branchData($request));

        return back()->with('success', 'Krak je kreiran.');
    }

    public function updateBranch(Request $request, $id)
    {
        $branch = NetworkBranch::findOrFail($id);
        $branch->update($this->branchData($request, $id, $branch->project_id));

        return back()->with('success', 'Krak je ažuriran.');
    }

    public function deleteBranch($id)
    {
        $branch = NetworkBranch::findOrFail($id);
        $branch->cabinets()->update(['branch_id' => null, 'branch_order' => 0]);
        $branch->childBranches()->update(['parent_branch_id' => null]);
        $branch->delete();

        return back()->with('success', 'Krak je obrisan.');
    }

    public function reorderBranches(Request $request)
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:network_branches,id'],
        ])['ids'];
        $projectIds = NetworkBranch::whereIn('id', $ids)->pluck('project_id')->unique();
        abort_if($projectIds->count() !== 1, 422, 'Redoslijed se može mijenjati samo unutar jednog projekta.');
        $projectId = $projectIds->first();

        foreach ($ids as $position => $id) {
            NetworkBranch::where('project_id', $projectId)->whereKey($id)->update(['sort_order' => $position + 1]);
        }

        return response()->json(['ok' => true]);
    }
}

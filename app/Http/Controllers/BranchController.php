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

    public function branches(): View
    {
        $branchStats = [
            'total' => NetworkBranch::whereNotIn('type', ['rov'])->count(),
            'primary' => NetworkBranch::where('type', 'primary')->count(),
            'secondary' => NetworkBranch::where('type', 'secondary')->count(),
            'cabinets' => Cabinet::whereNotNull('branch_id')->count(),
            'rov' => NetworkBranch::where('type', 'rov')->count(),
        ];

        return view('ftth.branches', [
            'branches' => NetworkBranch::with(['project', 'odf', 'parentBranch', 'route'])->withCount('cabinets')->orderBy('project_id')->orderBy('sort_order')->paginate(30),
            'projects' => Project::orderBy('name')->get(),
            'odfs' => Odf::with('project')->orderBy('name')->get(),
            'parentBranches' => NetworkBranch::with('project')->orderBy('name')->get(),
            'routes' => NetworkRoute::with('project')->whereIn('route_type', ['backbone', 'feeder', 'distribution'])->orderBy('name')->get(),
            'branchStats' => $branchStats,
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

        return back()->with('success', 'Krak je azuriran.');
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

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

class BranchController extends Controller
{
    use ManagesFtthData;

    public function branches(): View
    {
        $branchStats = [
            'total' => NetworkBranch::count(),
            'primary' => NetworkBranch::where('type', 'primary')->count(),
            'secondary' => NetworkBranch::where('type', 'secondary')->count(),
            'cabinets' => Cabinet::whereNotNull('branch_id')->count(),
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
        NetworkBranch::findOrFail($id)->update($this->branchData($request, $id));
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
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer|exists:network_branches,id'])['ids'];
        foreach ($ids as $position => $id) {
            NetworkBranch::where('id', $id)->update(['sort_order' => $position + 1]);
        }
        return response()->json(['ok' => true]);
    }
}


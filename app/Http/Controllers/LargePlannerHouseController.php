<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\LargePlannerHouseImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LargePlannerHouseController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse|RedirectResponse
    {
        $this->ensureLargeProject($project);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255', Rule::unique('houses')->where('project_id', $project->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $house = $project->houses()->create($data + ['status' => 'planned']);

        if ($request->expectsJson()) {
            return response()->json(['house' => $house], 201);
        }

        return back()->with('success', 'Kuća je dodana u ulazne podatke velikog planera.');
    }

    public function import(Request $request, Project $project, LargePlannerHouseImportService $importer): RedirectResponse
    {
        $this->ensureLargeProject($project);
        $data = $request->validate([
            'houses_file' => ['required', 'file', 'max:'.config('uploads.large_planner_houses_csv_kb'), 'extensions:csv,txt'],
        ]);
        $count = $importer->import($project, $data['houses_file']);

        return back()->with('success', "Uvezeno je {$count} kuća za veliki planer.");
    }

    private function ensureLargeProject(Project $project): void
    {
        abort_unless($project->planning_mode === 'large_auto', 404);
    }
}

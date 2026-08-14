<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\Project;
use App\Services\AutoGisPlannerService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class ProjectPlanningController extends Controller
{
    use ManagesFtthData;

    public function previewOdo(Request $request, Project $project)
    {
        try {
            return response()->json($this->ftthIntelligence->previewOdoPlan($project, $request->all()));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function previewGis(Request $request, Project $project, AutoGisPlannerService $planner)
    {
        return response()->json($planner->preview($project, $this->validatedLimit($request)));
    }

    public function confirmGis(Request $request, Project $project, AutoGisPlannerService $planner)
    {
        try {
            return response()->json($planner->confirm($project, $this->validatedLimit($request)), 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function confirmOdo(Request $request, Project $project)
    {
        $data = $request->validate([
            'plan' => ['required', 'array'],
        ]);

        try {
            return response()->json($this->ftthIntelligence->confirmOdoPlan(
                $project,
                $data['plan'],
                false
            ), 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['message' => 'Plan nije snimljen. Sve izmjene su ponistene.'], 500);
        }
    }

    public function validateProject(Project $project)
    {
        return response()->json([
            'project' => ['id' => $project->id, 'name' => $project->name],
            'items' => $this->projectValidation->validateProject($project),
            'materials' => $this->projectMaterials->summary($project),
        ]);
    }

    private function validatedLimit(Request $request): int
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:300']]);

        return (int) ($data['limit'] ?? 80);
    }
}

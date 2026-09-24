<?php

namespace App\Http\Controllers;

use App\Models\LargePlannerConstraint;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LargePlannerConstraintController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->planning_mode === 'large_auto', 404);

        $data = $request->validate([
            'type' => ['required', Rule::in(LargePlannerConstraint::TYPES)],
            'name' => ['nullable', 'string', 'max:120'],
            'geometry' => ['required', 'array'],
        ]);

        Validator::make($data, $this->geometryRules($data['type']))->validate();
        $constraint = $project->largePlannerConstraints()->create($data);

        return response()->json(['constraint' => $constraint], 201);
    }

    public function destroy(Project $project, LargePlannerConstraint $constraint): JsonResponse
    {
        abort_unless($project->planning_mode === 'large_auto' && $constraint->project_id === $project->id, 404);
        $constraint->delete();

        return response()->json(['deleted' => true]);
    }

    private function geometryRules(string $type): array
    {
        if ($type === 'required_waypoint') {
            return [
                'geometry' => ['array', 'size:2'],
                'geometry.0' => ['numeric', 'between:-90,90'],
                'geometry.1' => ['numeric', 'between:-180,180'],
            ];
        }

        return [
            'geometry' => ['array', 'min:3', 'max:10000'],
            'geometry.*' => ['array', 'size:2'],
            'geometry.*.0' => ['numeric', 'between:-90,90'],
            'geometry.*.1' => ['numeric', 'between:-180,180'],
        ];
    }
}

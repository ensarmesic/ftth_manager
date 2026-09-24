<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\LargePlannerInputValidationService;
use Illuminate\Http\JsonResponse;

class LargePlannerInputValidationController extends Controller
{
    public function __invoke(Project $project, LargePlannerInputValidationService $validator): JsonResponse
    {
        abort_unless($project->planning_mode === 'large_auto', 404);

        return response()->json($validator->validate($project));
    }
}

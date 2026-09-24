<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\LargePlannerReadinessService;
use Illuminate\Http\JsonResponse;

class LargePlannerReadinessController extends Controller
{
    public function __invoke(Project $project, LargePlannerReadinessService $readiness): JsonResponse
    {
        abort_unless($project->planning_mode === 'large_auto', 404);

        return response()->json($readiness->assess($project));
    }
}

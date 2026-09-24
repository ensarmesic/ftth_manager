<?php

namespace App\Http\Controllers;

use App\Models\GisSegment;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LargePlannerCorridorController extends Controller
{
    public function update(Request $request, Project $project, GisSegment $segment): JsonResponse
    {
        abort_unless($project->planning_mode === 'large_auto', 404);
        abort_unless($segment->project_id === $project->id && $segment->is_allowed, 404);

        $data = $request->validate([
            'planning_corridor_type' => ['nullable', Rule::in(GisSegment::PLANNING_CORRIDOR_TYPES)],
        ]);

        $segment->update($data);

        return response()->json([
            'message' => 'Vrsta koridora je sačuvana.',
            'segment' => [
                'id' => $segment->id,
                'planning_corridor_type' => $segment->planning_corridor_type,
            ],
        ]);
    }
}

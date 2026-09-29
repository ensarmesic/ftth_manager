<?php

namespace App\Http\Controllers;

use App\Models\GisSegment;
use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LargePlannerCorridorController extends Controller
{
    public function syncTrenches(Project $project): JsonResponse
    {
        abort_unless($project->planning_mode === 'large_auto', 404);
        $trenches = $project->routes()
            ->where('route_type', 'trench')
            ->whereNotNull('path')
            ->orderBy('id')->get()
            ->filter(fn (NetworkRoute $route) => count($route->path ?? []) >= 2);

        $existing = $project->gisSegments()
            ->where('source', 'large-planner-trench')
            ->get()
            ->keyBy(fn (GisSegment $segment) => (int) data_get($segment->properties, 'source_route_id'));
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($project, $trenches, $existing, &$created, &$updated): void {
            foreach ($trenches as $route) {
                $attributes = [
                    'name' => $route->name ?: "Glavni rov #{$route->id}",
                    'source' => 'large-planner-trench',
                    'segment_type' => 'corridor',
                    'is_allowed' => true,
                    'planning_corridor_type' => 'main',
                    'length_m' => $route->duct_length_m ?: 0,
                    'path' => $route->path,
                    'properties' => ['source_route_id' => $route->id, 'synced_from' => 'network_route'],
                ];
                if ($segment = $existing->get($route->id)) {
                    $segment->update($attributes);
                    $updated++;
                } else {
                    $project->gisSegments()->create($attributes);
                    $created++;
                }
            }
        });

        return response()->json([
            'message' => $trenches->isEmpty()
                ? 'Nije pronađen nijedan sačuvan glavni rov sa geometrijom.'
                : "Dozvoljeni koridori su osvježeni iz glavnih rovova ({$created} novih, {$updated} ažuriranih).",
            'created' => $created,
            'updated' => $updated,
            'total' => $trenches->count(),
        ], $trenches->isEmpty() ? 422 : 200);
    }

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

<?php

namespace App\Http\Controllers;

use App\Models\NetworkRoute;
use App\Models\Project;
use App\Services\GeometryService;
use App\Services\ProjectSnapshotService;
use App\Services\RouteGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DropRouteMaintenanceController extends Controller
{
    public function audit(Project $project, RouteGraphService $graph, GeometryService $geometry): JsonResponse
    {
        $items = $this->candidates($project, $graph, $geometry);

        return response()->json([
            'total' => $items->count(),
            'repairable' => $items->where('needs_repair', true)->whereNotNull('suggested_path')->count(),
            'aligned' => $items->where('needs_repair', false)->count(),
            'unreachable' => $items->whereNull('suggested_path')->count(),
            'items' => $items->map(fn (array $item) => [
                'route_id' => $item['route']->id,
                'route' => $item['route']->name,
                'house' => $item['route']->house?->label,
                'status' => ! $item['suggested_path'] ? 'unreachable' : ($item['needs_repair'] ? 'repairable' : 'aligned'),
                'source' => $item['source'],
                'max_deviation_m' => $item['max_deviation_m'],
            ])->values(),
        ]);
    }

    public function repair(Project $project, RouteGraphService $graph, GeometryService $geometry, ProjectSnapshotService $snapshots): JsonResponse
    {
        $items = $this->candidates($project, $graph, $geometry);
        if ($items->where('needs_repair', true)->whereNotNull('suggested_path')->isNotEmpty()) {
            $snapshots->create($project, 'Automatski: prije popravke drop-trasa');
        }
        $updated = DB::transaction(function () use ($items, $geometry): int {
            $count = 0;
            foreach ($items as $item) {
                if (! $item['suggested_path'] || ! $item['needs_repair']) {
                    continue;
                }
                $route = $item['route'];
                $path = $geometry->compactPath($item['suggested_path']);
                $route->update([
                    'path' => $path,
                    'duct_length_m' => $geometry->polylineLength($path),
                    'fiber_length_m' => $geometry->polylineLength($path),
                    'note' => trim(($route->note ? $route->note.' | ' : '').'Automatski popravljeno: kuća → fizička trasa → ODO.'),
                ]);
                $count++;
            }

            return $count;
        });

        return response()->json([
            'message' => "Popravljeno {$updated} korisničkih drop-trasa.",
            'updated' => $updated,
            'unreachable' => $items->whereNull('suggested_path')->count(),
        ]);
    }

    private function candidates(Project $project, RouteGraphService $graph, GeometryService $geometry)
    {
        return NetworkRoute::query()
            ->where('project_id', $project->id)
            ->where('route_type', 'drop')
            ->with(['house', 'cabinet'])
            ->get()
            ->map(function (NetworkRoute $route) use ($project, $graph, $geometry): array {
                $house = $route->house;
                $cabinet = $route->cabinet;
                $result = ($house?->latitude !== null && $house?->longitude !== null && $cabinet?->latitude !== null && $cabinet?->longitude !== null)
                    ? $graph->shortestPath(
                        $project->id,
                        [(float) $house->latitude, (float) $house->longitude],
                        [(float) $cabinet->latitude, (float) $cabinet->longitude],
                        true,
                        true,
                    )
                    : null;

                $suggestedPath = $result['path'] ?? null;
                $maxDeviation = $suggestedPath ? $this->maxPathDeviation($route->path ?? [], $suggestedPath, $geometry) : null;
                $lengthDifference = $suggestedPath
                    ? abs($geometry->polylineLength($route->path ?? []) - $geometry->polylineLength($suggestedPath))
                    : null;

                return [
                    'route' => $route,
                    'suggested_path' => $suggestedPath,
                    'source' => $result['graph']['source'] ?? null,
                    'max_deviation_m' => $maxDeviation === null ? null : round($maxDeviation, 2),
                    'needs_repair' => $suggestedPath !== null && ($maxDeviation > 1.5 || $lengthDifference > 2.0),
                ];
            });
    }

    private function maxPathDeviation(array $current, array $suggested, GeometryService $geometry): float
    {
        if (count($current) < 2 || count($suggested) < 2) {
            return INF;
        }

        $suggestedRoute = new NetworkRoute(['path' => $suggested]);
        $samples = [];
        for ($index = 1; $index < count($current); $index++) {
            $a = $current[$index - 1];
            $b = $current[$index];
            $samples[] = $a;
            $samples[] = [((float) $a[0] + (float) $b[0]) / 2, ((float) $a[1] + (float) $b[1]) / 2];
        }
        $samples[] = end($current);

        return max(array_map(fn (array $point) => $geometry->projectPointToRoute(
            (float) $point[0],
            (float) $point[1],
            $suggestedRoute,
        )['distance_m'], $samples));
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Services\RouteGraphService;
use Illuminate\Support\Facades\DB;

class MissingDropRouteController extends Controller
{
    use ManagesFtthData;

    public function __invoke(Project $project, RouteGraphService $graph)
    {
        $houses = $project->houses()->with('cabinet')
            ->whereNotNull('cabinet_id')->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('routes')
                ->whereColumn('routes.to_id', 'houses.id')
                ->where('routes.project_id', $project->id)
                ->where('routes.route_type', 'drop')
                ->where('routes.to_type', 'house'))
            ->get();

        $routes = DB::transaction(fn () => $houses->map(function (House $house) use ($project, $graph) {
            $cabinet = $house->cabinet;
            if (! $cabinet || $cabinet->latitude === null || $cabinet->longitude === null) {
                return null;
            }

            $gisRoute = $graph->shortestPath(
                $project->id,
                [(float) $cabinet->latitude, (float) $cabinet->longitude],
                [(float) $house->latitude, (float) $house->longitude],
            );
            $usesGis = $gisRoute && ($gisRoute['graph']['source'] ?? null) === 'gis';
            $path = $usesGis ? $gisRoute['path'] : $this->dropPathForHouse($cabinet, $house);
            $length = $this->polylineLength($path);

            return NetworkRoute::create([
                'project_id' => $project->id,
                'cabinet_id' => $cabinet->id,
                'from_type' => 'cabinet',
                'from_id' => $cabinet->id,
                'to_type' => 'house',
                'to_id' => $house->id,
                'name' => $this->uniqueProjectName(NetworkRoute::class, $project->id, "Drop {$cabinet->name}-{$house->label}"),
                'route_type' => 'drop',
                'installation_type' => 'underground',
                'duct_length_m' => $length,
                'fiber_length_m' => $length,
                'fiber_count' => 4,
                'microduct_count' => 1,
                'microduct_type' => '10/8',
                'status' => 'planned',
                'path' => $path,
                'note' => $usesGis ? 'Automatski rutirano kroz GIS graf.' : null,
            ]);
        })->filter()->values());

        return response()->json([
            'message' => 'Nedostajuce drop trase su kreirane.',
            'created' => $routes->count(),
            'routes' => $routes->map(fn (NetworkRoute $route) => [
                'id' => $route->id,
                'name' => $route->name,
                'type' => $route->route_type,
                'length' => $route->duct_length_m,
                'duct_length_m' => $route->duct_length_m,
                'microduct' => $route->microduct_type,
                'fibers' => $route->fiber_count,
                'path' => $route->path,
                'note' => $route->note,
                'from_type' => $route->from_type,
                'from_id' => $route->from_id,
                'to_type' => $route->to_type,
                'to_id' => $route->to_id,
                'cabinet_id' => $route->cabinet_id,
            ]),
        ]);
    }
}

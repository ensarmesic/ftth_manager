<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesFtthData;
use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RouteController extends Controller
{
    use ManagesFtthData;

    public function routes(): View
    {
        $routes = NetworkRoute::with(['project', 'odf', 'cabinet'])->where('route_type', '!=', 'trench')->latest()->paginate(12);
        $totalDuct = NetworkRoute::where('route_type', 'trench')->sum('duct_length_m');
        $effectiveMicroduct = NetworkRoute::query()
            ->where('route_type', '!=', 'trench')
            ->selectRaw('SUM(duct_length_m * microduct_count) as total')
            ->value('total') ?? 0;
        $totalFiber = NetworkRoute::where('route_type', '!=', 'trench')->sum('fiber_length_m');

        return view('ftth.routes', [
            'routes' => $routes,
            'projects' => Project::orderBy('name')->get(),
            'odfs' => Odf::orderBy('name')->get(),
            'cabinets' => Cabinet::orderBy('name')->get(),
            'routeStats' => [
                'duct' => $totalDuct,
                'effective_microduct' => $effectiveMicroduct,
                'microduct_with_reserve' => ceil($effectiveMicroduct * 1.1),
                'fiber' => $totalFiber,
                'fiber_with_reserve' => ceil($totalFiber * 1.1),
                'planned_cabinets' => Cabinet::count(),
                'planned_splitters' => Cabinet::sum('splitter_count'),
            ],
        ]);
    }

    public function storeRoute(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'odf_id' => ['nullable', 'exists:odfs,id'],
            'from_type' => ['nullable', 'in:odf,cabinet'],
            'from_id' => ['nullable', 'integer'],
            'to_type' => ['nullable', 'in:cabinet,house'],
            'to_id' => ['nullable', 'integer'],
            'cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'name' => ['required', 'max:255'],
            'route_type' => ['required', 'in:trench,backbone,feeder,distribution,drop'],
            'installation_type' => ['required', 'in:aerial,underground'],
            'trench_group' => ['nullable', 'string', 'max:80'],
            'counts_as_trench' => ['nullable', 'boolean'],
            'trench_length_m' => ['nullable', 'integer', 'min:0'],
            'duct_length_m' => ['required', 'integer', 'min:0'],
            'fiber_length_m' => ['required', 'integer', 'min:0'],
            'fiber_count' => ['required', 'integer', 'in:4,12,24,48'],
            'installation_type' => ['required', 'in:underground,aerial'],
            'microduct_count' => ['nullable', 'integer', 'min:0', 'max:48'],
            'microduct_count' => ['required', 'integer', 'min:0'],
            'microduct_type' => ['nullable', 'in:14/10,10/8'],
            'status' => ['required', 'in:planned,in_progress,built'],
            'path' => ['nullable', 'json'],
            'note' => ['nullable', 'string'],
        ]);
        $data['counts_as_trench'] = $data['route_type'] === 'trench';
        $data['trench_length_m'] = null;
        if ($data['route_type'] === 'trench') {
            $data['microduct_count'] = 0;
            $data['microduct_type'] = null;
            $data['fiber_length_m'] = 0;
            $data['fiber_count'] = 4;
        }
        $this->ensureBelongsToProject(Odf::class, $data['odf_id'] ?? null, $data['project_id'], 'odf_id');
        $this->ensureBelongsToProject(Cabinet::class, $data['cabinet_id'] ?? null, $data['project_id'], 'cabinet_id');
        $this->ensureRouteEndBelongsToProject($data, (int) $data['project_id']);
        $this->normalizeRouteStart($data, (int) $data['project_id']);

        if (! empty($data['path'])) {
            $data['path'] = json_decode($data['path'], true);
            $data['duct_length_m'] = $this->polylineLength($data['path']);
            $data['fiber_length_m'] = $data['route_type'] === 'trench' ? 0 : $data['duct_length_m'];
        }

        $route = NetworkRoute::create($data);
        $this->createBranchForRoute($route);
        $fromLabel = $this->routeStartLabel($route);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Trasa je evidentirana.',
                'route' => [
                    'id' => $route->id,
                    'name' => $route->name,
                    'from' => $fromLabel,
                    'to' => $route->cabinet?->name ?? '-',
                    'type' => $route->route_type,
                    'length' => $route->duct_length_m,
                    'microduct' => $route->microduct_type,
                    'fibers' => $route->fiber_count,
                    'path' => $route->path,
                    'note' => $route->note,
                    'from_type' => $route->from_type,
                    'from_id' => $route->from_id,
                    'to_type' => $route->to_type,
                    'to_id' => $route->to_id,
                    'odf_id' => $route->odf_id,
                    'cabinet_id' => $route->cabinet_id,
                ],
            ], 201);
        }

        return back()->with('success', 'Trasa je evidentirana.');
    }

    public function deleteRoute($id)
    {
        $this->deleteRouteWithBranch(NetworkRoute::findOrFail($id));
        if (request()->expectsJson()) {
            return response()->json(['message' => 'Trasa je obrisana.']);
        }

        return back()->with('success', 'Trasa je obrisana.');
    }

    public function updateRoute(Request $request, $id)
    {
        $route = NetworkRoute::findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'max:255'],
            'route_type' => ['required', 'in:trench,backbone,feeder,distribution,drop'],
            'odf_id' => ['nullable', 'exists:odfs,id'],
            'from_type' => ['nullable', 'in:odf,cabinet'],
            'from_id' => ['nullable', 'integer'],
            'to_type' => ['nullable', 'in:cabinet,house'],
            'to_id' => ['nullable', 'integer'],
            'cabinet_id' => ['nullable', 'exists:cabinets,id'],
            'microduct_type' => ['nullable', 'in:14/10,10/8'],
            'fiber_count' => ['required', 'integer', 'in:4,12,24,48'],
            'trench_group' => ['nullable', 'string', 'max:80'],
            'counts_as_trench' => ['nullable', 'boolean'],
            'trench_length_m' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);
        $data['counts_as_trench'] = $data['route_type'] === 'trench';
        $data['trench_length_m'] = null;
        if ($data['route_type'] === 'trench') {
            $data['microduct_type'] = null;
            $data['fiber_count'] = 4;
            $data['microduct_count'] = 0;
        }
        $this->ensureBelongsToProject(Odf::class, $data['odf_id'] ?? null, $route->project_id, 'odf_id');
        $this->ensureBelongsToProject(Cabinet::class, $data['cabinet_id'] ?? null, $route->project_id, 'cabinet_id');
        $this->ensureRouteEndBelongsToProject($data, (int) $route->project_id);
        $this->normalizeRouteStart($data, (int) $route->project_id);
        $route->update($data);
        $this->syncBranchForRoute($route);
        $route->load(['odf', 'cabinet']);

        if (! $request->expectsJson()) {
            return back()->with('success', 'Podaci trase su sačuvani.');
        }

        return response()->json([
            'message' => 'Podaci trase su sačuvani.',
            'route' => [
                'id' => $route->id, 'name' => $route->name, 'from' => $this->routeStartLabel($route),
                'to' => $route->cabinet?->name ?? '-', 'type' => $route->route_type, 'length' => $route->duct_length_m,
                'microduct' => $route->microduct_type, 'microduct_count' => $route->microduct_count, 'fibers' => $route->fiber_count, 'installation_type' => $route->installation_type, 'path' => $route->path,
                'note' => $route->note, 'odf_id' => $route->odf_id, 'from_type' => $route->from_type, 'from_id' => $route->from_id,
                'to_type' => $route->to_type, 'to_id' => $route->to_id, 'cabinet_id' => $route->cabinet_id,
            ],
        ]);
    }

    public function updateRouteGeometry(Request $request, $id)
    {
        $data = $request->validate([
            'path' => ['required', 'array', 'min:2'],
            'path.*' => ['required', 'array', 'size:2'],
            'path.*.*' => ['required', 'numeric'],
        ]);

        $route = NetworkRoute::findOrFail($id);
        $length = $this->polylineLength($data['path']);
        $route->update([
            'path' => $data['path'],
            'duct_length_m' => $length,
            'fiber_length_m' => $route->route_type === 'trench' ? 0 : $length,
        ]);

        return response()->json([
            'message' => 'Geometrija trase je sacuvana.',
            'route' => [
                'id' => $route->id,
                'path' => $route->path,
                'length' => $route->duct_length_m,
            ],
        ]);
    }

    public function splitRoute(Request $request, $id)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $route = NetworkRoute::findOrFail($id);
        abort_if(count($route->path ?? []) < 2, 422, 'Trasa mora imati najmanje dvije tačke.');

        $proj = $this->projectPointToRoute((float) $data['lat'], (float) $data['lng'], $route);
        $segIdx = (int) $proj['segment_index'];
        $splitPoint = [round($proj['lat'], 7), round($proj['lng'], 7)];

        $firstPath = array_merge(array_slice($route->path, 0, $segIdx), [$splitPoint]);
        $secondPath = array_merge([$splitPoint], array_slice($route->path, $segIdx));

        abort_if(count($firstPath) < 2 || count($secondPath) < 2, 422, 'Tačka podjele je previše blizu kraju trase.');

        $first = $second = null;

        DB::transaction(function () use ($route, $firstPath, $secondPath, &$first, &$second) {
            $route->update([
                'path' => $firstPath,
                'duct_length_m' => $this->polylineLength($firstPath),
                'fiber_length_m' => $route->route_type === 'trench' ? 0 : $this->polylineLength($firstPath),
            ]);

            $newRoute = $route->replicate()->fill([
                'name' => $route->name.'-B',
                'path' => $secondPath,
                'duct_length_m' => $this->polylineLength($secondPath),
                'fiber_length_m' => $route->route_type === 'trench' ? 0 : $this->polylineLength($secondPath),
                'from_type' => null,
                'from_id' => null,
            ]);
            $newRoute->save();
            $this->createBranchForRoute($newRoute);

            $first = $route->fresh();
            $second = $newRoute;
        });

        return response()->json([
            'message' => 'Trasa je podijeljena.',
            'first' => $this->routeToMapJson($first),
            'second' => $this->routeToMapJson($second),
        ]);
    }

    private function routeToMapJson(NetworkRoute $route): array
    {
        return [
            'id' => $route->id,
            'name' => $route->name,
            'type' => $route->route_type,
            'length' => $route->duct_length_m,
            'duct_length_m' => $route->duct_length_m,
            'fiber_count' => $route->fiber_count,
            'fibers' => $route->fiber_count,
            'microduct_type' => $route->microduct_type,
            'microduct_count' => $route->microduct_count,
            'installation_type' => $route->installation_type,
            'path' => $route->path,
            'odf_id' => $route->odf_id,
            'cabinet_id' => $route->cabinet_id,
            'from_type' => $route->from_type,
            'from_id' => $route->from_id,
            'to_type' => $route->to_type,
            'to_id' => $route->to_id,
            'note' => $route->note,
            'status' => $route->status,
            'project_id' => $route->project_id,
            'occupancy' => ['fiber_capacity' => $route->fiber_count, 'used_fibers' => 0, 'free_fibers' => $route->fiber_count, 'utilization_percent' => 0],
        ];
    }

    public function joinRoutes(Request $request, $id, $otherId = null)
    {
        $route = NetworkRoute::findOrFail($id);
        $joinIds = collect($otherId ? [$otherId] : $request->input('route_ids', []))
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0 && $value !== (int) $route->id)
            ->unique()
            ->values();

        abort_if($joinIds->isEmpty(), 422, 'Odaberi najmanje jednu trasu za spajanje.');
        abort_if(count($route->path ?? []) < 2, 422, 'Glavna trasa mora imati najmanje dvije tacke.');

        $others = NetworkRoute::query()->whereIn('id', $joinIds)->get();
        abort_if($others->count() !== $joinIds->count(), 404, 'Jedna ili vise trasa nije pronadjeno.');

        $path = $route->path;
        $deletedIds = [];

        DB::transaction(function () use ($route, $others, &$path, &$deletedIds) {
            foreach ($others as $other) {
                abort_if($route->project_id !== $other->project_id, 422, 'Trase moraju pripadati istom projektu.');
                abort_if(count($other->path ?? []) < 2, 422, 'Sve trase moraju imati najmanje dvije tacke.');

                [$path, $gap] = $this->joinedRoutePath($path, $other->path);
                abort_if($gap > 18, 422, 'Krajevi trasa moraju biti udaljeni najvise 18 m.');

                $primaryBranch = NetworkBranch::query()->where('route_id', $route->id)->first();
                $otherBranch = NetworkBranch::query()->where('route_id', $other->id)->first();
                if ($otherBranch) {
                    if (! $primaryBranch) {
                        $otherBranch->update(['route_id' => $route->id, 'name' => $route->name]);
                        $primaryBranch = $otherBranch;
                    } else {
                        $otherBranch->cabinets()->update(['branch_id' => $primaryBranch->id]);
                        $otherBranch->childBranches()->update(['parent_branch_id' => $primaryBranch->id]);
                        $otherBranch->delete();
                    }
                }
                $deletedIds[] = $other->id;
                $other->delete();
            }

            $length = $this->polylineLength($path);
            $route->update([
                'path' => $path,
                'duct_length_m' => $length,
                'fiber_length_m' => $route->route_type === 'trench' ? 0 : $length,
            ]);
        });

        $length = $this->polylineLength($path);

        return response()->json([
            'message' => 'Trase su spojene.',
            'route' => ['id' => $route->id, 'path' => $path, 'length' => $length],
            'deleted_route_id' => $deletedIds[0] ?? null,
            'deleted_route_ids' => $deletedIds,
        ]);
    }

    private function ensureRouteEndBelongsToProject(array $data, int $projectId): void
    {
        $toType = $data['to_type'] ?? null;
        $toId = $data['to_id'] ?? null;

        if ($toType === 'cabinet') {
            $this->ensureBelongsToProject(Cabinet::class, $toId, $projectId, 'to_id');
        }

        if ($toType === 'house') {
            $this->ensureBelongsToProject(House::class, $toId, $projectId, 'to_id');
        }
    }

    private function joinedRoutePath(array $a, array $b): array
    {
        $options = [
            [$a, $b, $this->pointDistance(end($a), reset($b))],
            [$a, array_reverse($b), $this->pointDistance(end($a), end($b))],
            [array_reverse($a), $b, $this->pointDistance(reset($a), reset($b))],
            [array_reverse($a), array_reverse($b), $this->pointDistance(reset($a), end($b))],
        ];
        usort($options, fn ($left, $right) => $left[2] <=> $right[2]);
        [$first, $second, $gap] = $options[0];

        return [array_merge($first, array_slice($second, 1)), $gap];
    }

    public function importDxf(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'dxf' => ['required', 'file', 'max:10240', 'extensions:dxf'],
        ]);

        $entities = $this->parseDxfPolylines($data['dxf']->get());
        if (! count($entities)) {
            return back()->withErrors(['dxf' => 'DXF nema podrzane LINE, LWPOLYLINE ili POLYLINE entitete.']);
        }

        DB::transaction(function () use ($data, $entities): void {
            foreach ($entities as $index => $points) {
                $length = $this->polylineLength($points);
                NetworkRoute::create([
                    'project_id' => $data['project_id'],
                    'name' => 'DXF trasa '.($index + 1),
                    'route_type' => 'distribution',
                    'installation_type' => 'underground',
                    'duct_length_m' => $length,
                    'fiber_length_m' => $length,
                    'fiber_count' => 12,
                    'microduct_count' => 1,
                    'microduct_type' => '14/10',
                    'status' => 'planned',
                    'path' => $points,
                ]);
            }
        });

        return back()->with('success', 'DXF importovan: '.count($entities).' trasa.');
    }
}

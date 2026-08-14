<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BranchSyncService
{
    public function __construct(private readonly GeometryService $geometry) {}

    /**
     * Create a NetworkBranch for a newly saved route (skip drop/trench types).
     */
    public function createBranchForRoute(NetworkRoute $route): ?NetworkBranch
    {
        if (in_array($route->route_type, ['drop', 'trench'], true) || NetworkBranch::query()->where('route_id', $route->id)->exists()) {
            return null;
        }

        $sourceCabinet = $route->from_type === 'cabinet' && $route->from_id
            ? Cabinet::query()->where('project_id', $route->project_id)->find($route->from_id)
            : null;

        $odfId = $route->odf_id ?? $sourceCabinet?->odf_id;
        if (! $odfId) {
            $projectOdfs = Odf::query()->where('project_id', $route->project_id)->pluck('id');
            if ($projectOdfs->count() === 1) {
                $odfId = $projectOdfs->first();
            }
        }

        $branch = NetworkBranch::create([
            'project_id' => $route->project_id,
            'odf_id' => $odfId,
            'parent_branch_id' => $sourceCabinet?->branch_id,
            'route_id' => $route->id,
            'name' => $route->name,
            'code' => preg_match('/(\d+(?:[.-]\d+)*)/', $route->name, $match) ? str_replace('-', '.', $match[1]) : null,
            'type' => in_array($route->route_type, ['backbone', 'feeder'], true) ? 'primary' : 'secondary',
            'sort_order' => (int) NetworkBranch::query()->where('project_id', $route->project_id)->max('sort_order') + 1,
        ]);

        if ($route->cabinet_id) {
            $cabinetUpdate = [
                'branch_id' => $branch->id,
                'odf_id' => $odfId,
            ];
            if ($sourceCabinet) {
                $cabinetUpdate['parent_cabinet_id'] = $sourceCabinet->id;
            }
            Cabinet::query()
                ->where('project_id', $route->project_id)
                ->whereKey($route->cabinet_id)
                ->update($cabinetUpdate);
        }

        return $branch;
    }

    /**
     * Keep an existing branch in sync when its route is updated.
     */
    public function syncBranchForRoute(NetworkRoute $route): void
    {
        $branch = NetworkBranch::query()->where('route_id', $route->id)->first();

        if (in_array($route->route_type, ['drop', 'trench'], true)) {
            if ($branch) {
                $branch->cabinets()->update(['branch_id' => null, 'branch_order' => 0]);
                $branch->childBranches()->update(['parent_branch_id' => null]);
                $branch->delete();
            }

            return;
        }

        if (! $branch) {
            $this->createBranchForRoute($route);

            return;
        }

        $syncOdfId = $route->odf_id;
        if (! $syncOdfId && ! $branch->odf_id) {
            $projectOdfs = Odf::query()->where('project_id', $route->project_id)->pluck('id');
            if ($projectOdfs->count() === 1) {
                $syncOdfId = $projectOdfs->first();
            }
        }

        $branch->update([
            'odf_id' => $syncOdfId ?? $branch->odf_id,
            'name' => $route->name,
            'code' => preg_match('/(\d+(?:[.-]\d+)*)/', $route->name, $match) ? str_replace('-', '.', $match[1]) : null,
            'type' => in_array($route->route_type, ['backbone', 'feeder'], true) ? 'primary' : 'secondary',
        ]);

        if ($route->cabinet_id) {
            Cabinet::query()
                ->where('project_id', $route->project_id)
                ->whereKey($route->cabinet_id)
                ->update([
                    'branch_id' => $branch->id,
                    'odf_id' => $syncOdfId ?? $branch->odf_id,
                ]);
        }
    }

    /**
     * Delete a route and its associated branch in a single transaction.
     */
    public function deleteRouteWithBranch(NetworkRoute $route): void
    {
        DB::transaction(function () use ($route): void {
            $branch = NetworkBranch::query()->where('route_id', $route->id)->first();
            if ($branch) {
                $branch->cabinets()->update(['branch_id' => null, 'branch_order' => 0]);
                $branch->childBranches()->update(['parent_branch_id' => null]);
                $branch->delete();
            }
            $route->delete();
        });
    }

    /**
     * Assign draft houses to the nearest secondary branch based on distance.
     */
    public function assignCreatedHousesToDraftBranches(Collection $houses, Collection $branches): void
    {
        if ($houses->isEmpty() || $branches->isEmpty()) {
            return;
        }

        foreach ($houses as $house) {
            $nearest = $branches
                ->map(fn (array $item) => [
                    'branch' => $item['branch'],
                    'distance_m' => $this->geometry->distanceToRoute(
                        (float) $house->latitude,
                        (float) $house->longitude,
                        $item['route']->path ?? []
                    ),
                ])
                ->sortBy('distance_m')
                ->first();

            if ($nearest && is_finite($nearest['distance_m'])) {
                $house->update(['branch_id' => $nearest['branch']->id]);
            }
        }
    }
}

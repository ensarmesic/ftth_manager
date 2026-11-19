<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\NetworkBranch;
use Illuminate\Support\Collection;

/**
 * Finds the cabinet a branch cable leaves from (a branch whose route starts at a cabinet).
 *
 * The route stores that cabinet's id. When cabinets were regenerated the id can point to a
 * cabinet that no longer exists; then the source is the cabinet of the parent branch (or of
 * the same ODF) nearest to where the cable starts.
 */
class BranchSourceResolver
{
    /** A cable starting further than this from every candidate is not guessed. */
    private const MAX_DISTANCE_M = 250.0;

    public function __construct(private readonly GeometryService $geometry) {}

    /**
     * @param  Collection<int, NetworkBranch>  $branches  with their route loaded
     * @param  Collection<int, Cabinet>  $cabinets
     * @return array<int, int> branch id => source cabinet id, only for branches leaving from a cabinet
     */
    public function resolve(Collection $branches, Collection $cabinets): array
    {
        $cabinetsById = $cabinets->keyBy('id');
        $sources = [];
        foreach ($branches as $branch) {
            $route = $branch->route;
            if ($route?->from_type !== 'cabinet') {
                continue;
            }
            if ($route->from_id && $cabinetsById->has((int) $route->from_id)) {
                $sources[$branch->id] = (int) $route->from_id;

                continue;
            }
            $path = $route->getAttribute('path');
            $start = is_array($path) ? (array_values($path)[0] ?? null) : null;
            if (! is_array($start) || count($start) < 2) {
                continue;
            }
            $candidates = $branch->parent_branch_id
                ? $cabinets->where('branch_id', $branch->parent_branch_id)
                : $cabinets->where('odf_id', $branch->odf_id);
            $candidates = $candidates->reject(fn (Cabinet $cabinet) => (int) $cabinet->branch_id === $branch->id || $cabinet->latitude === null || $cabinet->longitude === null);
            $best = null;
            $bestDistance = self::MAX_DISTANCE_M;
            foreach ($candidates as $cabinet) {
                $distance = $this->geometry->distanceMeters((float) $start[0], (float) $start[1], (float) $cabinet->latitude, (float) $cabinet->longitude);
                if ($distance <= $bestDistance) {
                    $best = $cabinet->id;
                    $bestDistance = $distance;
                }
            }
            if ($best) {
                $sources[$branch->id] = $best;
            }
        }

        return $sources;
    }
}

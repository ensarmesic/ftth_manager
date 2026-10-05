<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ManualCabinetHouseAssignmentService
{
    public function __construct(private readonly GeometryService $geometry) {}

    /**
     * Assign nearby houses to manually positioned cabinets without a distance cutoff.
     * The global minimum-cost assignment avoids one cabinet taking houses that are
     * much more important to a neighbouring cabinet.
     *
     * @param  Collection<int, Cabinet>  $cabinets
     * @return array<int, array{cabinet_id:int, house_id:int, distance_m:float}>
     */
    public function assign(Collection $cabinets, int $targetPerCabinet = 12): array
    {
        $cabinets = $cabinets->values();
        if ($cabinets->isEmpty() || $targetPerCabinet < 1 || $targetPerCabinet > 12) {
            throw new InvalidArgumentException('Odaberi ormare i cilj od 1 do 12 kuća po ormaru.');
        }

        $projectIds = $cabinets->pluck('project_id')->unique();
        if ($projectIds->count() !== 1 || $cabinets->contains(fn (Cabinet $cabinet) => $cabinet->latitude === null || $cabinet->longitude === null)) {
            throw new InvalidArgumentException('Svi ormari moraju pripadati istom projektu i imati koordinate.');
        }

        $cabinetIds = $cabinets->pluck('id')->all();
        $houses = House::query()
            ->where('project_id', $projectIds->first())
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(fn ($query) => $query->whereNull('cabinet_id')->orWhereIn('cabinet_id', $cabinetIds))
            ->get()
            ->values();
        $slotCount = $cabinets->count() * $targetPerCabinet;
        if ($houses->count() < $slotCount) {
            throw new InvalidArgumentException("Nema dovoljno dostupnih kuća za popunu {$slotCount} priključaka.");
        }

        $slots = [];
        foreach ($cabinets as $cabinet) {
            for ($index = 0; $index < $targetPerCabinet; $index++) {
                $slots[] = $cabinet;
            }
        }

        $cost = [[]];
        foreach ($slots as $row => $cabinet) {
            $cost[$row + 1] = [0.0];
            foreach ($houses as $house) {
                $cost[$row + 1][] = $this->geometry->distanceMeters(
                    (float) $cabinet->latitude,
                    (float) $cabinet->longitude,
                    (float) $house->latitude,
                    (float) $house->longitude,
                );
            }
        }

        $matching = $this->minimumCostMatching($cost, count($slots), $houses->count());
        $assignments = [];
        foreach ($matching as $houseColumn => $slotRow) {
            $house = $houses[$houseColumn - 1];
            $cabinet = $slots[$slotRow - 1];
            $assignments[] = [
                'cabinet_id' => (int) $cabinet->id,
                'house_id' => (int) $house->id,
                'distance_m' => round($cost[$slotRow][$houseColumn], 1),
            ];
        }

        DB::transaction(function () use ($projectIds, $cabinetIds, $assignments, $cabinets): void {
            House::query()->where('project_id', $projectIds->first())->whereIn('cabinet_id', $cabinetIds)->update(['cabinet_id' => null]);
            $branches = $cabinets->keyBy('id')->map(fn (Cabinet $cabinet) => $cabinet->branch_id);
            foreach ($assignments as $assignment) {
                House::query()->whereKey($assignment['house_id'])->update([
                    'cabinet_id' => $assignment['cabinet_id'],
                    'branch_id' => $branches[$assignment['cabinet_id']],
                ]);
            }
        });

        return $assignments;
    }

    /**
     * Give each house exclusively to its geographically nearest cabinet, then
     * keep only the nearest houses up to capacity. This intentionally permits
     * 3-11 connections instead of pulling a distant house merely to reach 12.
     *
     * @param  Collection<int, Cabinet>  $cabinets
     * @return array<int, array{cabinet_id:int, house_id:int, distance_m:float}>
     */
    public function assignStrictlyNearest(Collection $cabinets, int $capacity = 12, int $minimum = 3): array
    {
        $cabinets = $cabinets->values();
        if ($cabinets->isEmpty() || $capacity < 1 || $capacity > 12 || $minimum < 1 || $minimum > $capacity) {
            throw new InvalidArgumentException('Odaberi ormare, kapacitet do 12 i ispravan minimalni broj kuća.');
        }

        $projectIds = $cabinets->pluck('project_id')->unique();
        if ($projectIds->count() !== 1 || $cabinets->contains(fn (Cabinet $cabinet) => $cabinet->latitude === null || $cabinet->longitude === null)) {
            throw new InvalidArgumentException('Svi ormari moraju pripadati istom projektu i imati koordinate.');
        }

        $cabinetIds = $cabinets->pluck('id')->all();
        $houses = House::query()
            ->where('project_id', $projectIds->first())
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(fn ($query) => $query->whereNull('cabinet_id')->orWhereIn('cabinet_id', $cabinetIds))
            ->get();
        $groups = $cabinets->mapWithKeys(fn (Cabinet $cabinet) => [$cabinet->id => []])->all();

        foreach ($houses as $house) {
            $nearest = $cabinets->map(function (Cabinet $cabinet) use ($house): array {
                return [
                    'cabinet_id' => (int) $cabinet->id,
                    'house_id' => (int) $house->id,
                    'distance_m' => round($this->geometry->distanceMeters(
                        (float) $cabinet->latitude,
                        (float) $cabinet->longitude,
                        (float) $house->latitude,
                        (float) $house->longitude,
                    ), 1),
                ];
            })->sortBy('distance_m')->first();
            $groups[$nearest['cabinet_id']][] = $nearest;
        }

        $assignments = [];
        foreach ($cabinets as $cabinet) {
            $nearest = collect($groups[$cabinet->id])->sortBy('distance_m')->take($capacity)->values();
            if ($nearest->count() < $minimum) {
                throw new InvalidArgumentException("ODO {$cabinet->name} ima samo {$nearest->count()} najbližih kuća; minimum je {$minimum}.");
            }
            array_push($assignments, ...$nearest->all());
        }

        DB::transaction(function () use ($projectIds, $cabinetIds, $assignments, $cabinets): void {
            House::query()->where('project_id', $projectIds->first())->whereIn('cabinet_id', $cabinetIds)->update(['cabinet_id' => null]);
            $branches = $cabinets->keyBy('id')->map(fn (Cabinet $cabinet) => $cabinet->branch_id);
            foreach ($assignments as $assignment) {
                House::query()->whereKey($assignment['house_id'])->update([
                    'cabinet_id' => $assignment['cabinet_id'],
                    'branch_id' => $branches[$assignment['cabinet_id']],
                ]);
            }
        });

        return $assignments;
    }

    /**
     * Assign by a combined cabinet/branch score. Direct cabinet distance remains
     * dominant, while proximity to the cabinet's actual secondary route prevents
     * assignments that jump across a neighbouring road or branch.
     */
    public function assignByRouteProximity(Collection $cabinets, int $capacity = 12, int $minimum = 3, bool $assignOverflow = false, float $maxOverflowDistance = 120): array
    {
        $cabinets = $cabinets->values();
        if ($cabinets->isEmpty() || $capacity < 1 || $capacity > 12 || $minimum < 1 || $minimum > $capacity) {
            throw new InvalidArgumentException('Odaberi ormare, kapacitet do 12 i ispravan minimalni broj kuća.');
        }
        $projectIds = $cabinets->pluck('project_id')->unique();
        if ($projectIds->count() !== 1 || $cabinets->contains(fn (Cabinet $cabinet) => ! $cabinet->branch_id || $cabinet->latitude === null || $cabinet->longitude === null)) {
            throw new InvalidArgumentException('Svi ormari moraju pripadati istom projektu, kraku i imati koordinate.');
        }

        $branches = NetworkBranch::query()->with('route')->whereIn('id', $cabinets->pluck('branch_id'))->get()->keyBy('id');
        if ($cabinets->contains(fn (Cabinet $cabinet) => count($branches[$cabinet->branch_id]?->route?->path ?? []) < 2)) {
            throw new InvalidArgumentException('Svaki ormar mora imati krak sa nacrtanom trasom.');
        }

        $cabinetIds = $cabinets->pluck('id')->all();
        $houses = House::query()
            ->where('project_id', $projectIds->first())
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->where(fn ($query) => $query->whereNull('cabinet_id')->orWhereIn('cabinet_id', $cabinetIds))
            ->get();
        $groups = $cabinets->mapWithKeys(fn (Cabinet $cabinet) => [$cabinet->id => []])->all();

        foreach ($houses as $house) {
            $best = $cabinets->map(function (Cabinet $cabinet) use ($house, $branches): array {
                $cabinetDistance = $this->geometry->distanceMeters(
                    (float) $house->latitude, (float) $house->longitude,
                    (float) $cabinet->latitude, (float) $cabinet->longitude,
                );
                $route = $branches[$cabinet->branch_id]->route;
                $houseProjection = $this->geometry->projectPointToRoute((float) $house->latitude, (float) $house->longitude, $route);
                $cabinetProjection = $this->geometry->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $route);
                $routeDistance = $houseProjection['distance_m'];
                $networkDistance = $routeDistance
                    + $cabinetProjection['distance_m']
                    + abs($houseProjection['chainage_m'] - $cabinetProjection['chainage_m']);

                return [
                    'cabinet_id' => (int) $cabinet->id,
                    'house_id' => (int) $house->id,
                    'distance_m' => round($cabinetDistance, 1),
                    'route_distance_m' => round($routeDistance, 1),
                    'network_distance_m' => round($networkDistance, 1),
                    'score' => $networkDistance + (0.1 * $cabinetDistance),
                ];
            })->sortBy('score')->first();
            $groups[$best['cabinet_id']][] = $best;
        }

        $assignments = [];
        foreach ($cabinets as $cabinet) {
            $nearest = collect($groups[$cabinet->id])->sortBy('score')->take($capacity)->values();
            if ($nearest->count() < $minimum) {
                throw new InvalidArgumentException("ODO {$cabinet->name} ima samo {$nearest->count()} kuća na svom najbližem kraku; minimum je {$minimum}.");
            }
            array_push($assignments, ...$nearest->all());
        }

        if ($assignOverflow) {
            $assignedHouseIds = collect($assignments)->pluck('house_id')->flip();
            $loads = collect($assignments)->countBy('cabinet_id')->all();
            $overflowPairs = [];
            foreach ($houses as $house) {
                if ($assignedHouseIds->has($house->id)) {
                    continue;
                }
                foreach ($cabinets as $cabinet) {
                    $cabinetDistance = $this->geometry->distanceMeters(
                        (float) $house->latitude, (float) $house->longitude,
                        (float) $cabinet->latitude, (float) $cabinet->longitude,
                    );
                    if ($cabinetDistance > $maxOverflowDistance) {
                        continue;
                    }
                    $route = $branches[$cabinet->branch_id]->route;
                    $houseProjection = $this->geometry->projectPointToRoute((float) $house->latitude, (float) $house->longitude, $route);
                    $cabinetProjection = $this->geometry->projectPointToRoute((float) $cabinet->latitude, (float) $cabinet->longitude, $route);
                    $routeDistance = $houseProjection['distance_m'];
                    $networkDistance = $routeDistance
                        + $cabinetProjection['distance_m']
                        + abs($houseProjection['chainage_m'] - $cabinetProjection['chainage_m']);
                    $overflowPairs[] = [
                        'cabinet_id' => (int) $cabinet->id,
                        'house_id' => (int) $house->id,
                        'distance_m' => round($cabinetDistance, 1),
                        'route_distance_m' => round($routeDistance, 1),
                        'network_distance_m' => round($networkDistance, 1),
                        'score' => $networkDistance + (0.1 * $cabinetDistance),
                    ];
                }
            }
            usort($overflowPairs, fn (array $left, array $right) => $left['score'] <=> $right['score']);
            foreach ($overflowPairs as $candidate) {
                if ($assignedHouseIds->has($candidate['house_id']) || ($loads[$candidate['cabinet_id']] ?? 0) >= $capacity) {
                    continue;
                }
                $assignments[] = $candidate;
                $assignedHouseIds->put($candidate['house_id'], true);
                $loads[$candidate['cabinet_id']] = ($loads[$candidate['cabinet_id']] ?? 0) + 1;
            }

        }

        DB::transaction(function () use ($projectIds, $cabinetIds, $assignments, $cabinets): void {
            House::query()->where('project_id', $projectIds->first())->whereIn('cabinet_id', $cabinetIds)->update(['cabinet_id' => null]);
            $branches = $cabinets->keyBy('id')->map(fn (Cabinet $cabinet) => $cabinet->branch_id);
            foreach ($assignments as $assignment) {
                House::query()->whereKey($assignment['house_id'])->update([
                    'cabinet_id' => $assignment['cabinet_id'],
                    'branch_id' => $branches[$assignment['cabinet_id']],
                ]);
            }
        });

        return $assignments;
    }

    /** @return array<int, int> house column => slot row */
    private function minimumCostMatching(array $cost, int $rows, int $columns): array
    {
        $u = array_fill(0, $rows + 1, 0.0);
        $v = array_fill(0, $columns + 1, 0.0);
        $matchedRow = array_fill(0, $columns + 1, 0);
        $way = array_fill(0, $columns + 1, 0);

        for ($row = 1; $row <= $rows; $row++) {
            $matchedRow[0] = $row;
            $column = 0;
            $minimum = array_fill(0, $columns + 1, INF);
            $used = array_fill(0, $columns + 1, false);
            do {
                $used[$column] = true;
                $currentRow = $matchedRow[$column];
                $delta = INF;
                $nextColumn = 0;
                for ($candidate = 1; $candidate <= $columns; $candidate++) {
                    if ($used[$candidate]) {
                        continue;
                    }
                    $reducedCost = $cost[$currentRow][$candidate] - $u[$currentRow] - $v[$candidate];
                    if ($reducedCost < $minimum[$candidate]) {
                        $minimum[$candidate] = $reducedCost;
                        $way[$candidate] = $column;
                    }
                    if ($minimum[$candidate] < $delta) {
                        $delta = $minimum[$candidate];
                        $nextColumn = $candidate;
                    }
                }
                for ($candidate = 0; $candidate <= $columns; $candidate++) {
                    if ($used[$candidate]) {
                        $u[$matchedRow[$candidate]] += $delta;
                        $v[$candidate] -= $delta;
                    } else {
                        $minimum[$candidate] -= $delta;
                    }
                }
                $column = $nextColumn;
            } while ($matchedRow[$column] !== 0);

            do {
                $previous = $way[$column];
                $matchedRow[$column] = $matchedRow[$previous];
                $column = $previous;
            } while ($column !== 0);
        }

        $result = [];
        for ($column = 1; $column <= $columns; $column++) {
            if ($matchedRow[$column] > 0) {
                $result[$column] = $matchedRow[$column];
            }
        }

        return $result;
    }
}

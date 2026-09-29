<?php

namespace App\Services\LargePlanner;

use App\Models\GisSegment;
use App\Models\Project;
use App\Services\GeometryService;
use DomainException;

class PreviewEditorService
{
    private const SNAP_LIMIT_M = 50;

    private const MIN_ODF_ODO_DISTANCE_M = 5;

    public function __construct(
        private readonly GeometryService $geometry,
        private readonly CorridorGraphBuilder $graphs,
        private readonly NetworkRouteProposalService $routes,
        private readonly OdfProposalService $odfProposals,
        private readonly CableCapacityService $capacities,
        private readonly PlanWarningService $warnings,
    ) {}

    public function move(Project $project, array $preview, string $type, string $key, array $point): array
    {
        $path = $type === 'odf' ? ['odf_placement', 'odfs'] : ['odo_placement', 'odos'];
        $items = data_get($preview, implode('.', $path), []);
        $index = collect($items)->search(fn (array $item) => $item['key'] === $key);
        if ($index === false) {
            throw new DomainException('Predloženi element nije pronađen.');
        }
        if ($items[$index]['locked'] ?? false) {
            throw new DomainException('Zaključani element nije moguće pomjeriti.');
        }

        $snap = $this->nearestCorridor($project, $point);
        if ($snap === null || $snap['distance_m'] > self::SNAP_LIMIT_M) {
            throw new DomainException('Nova pozicija mora biti uz dozvoljeni koridor.');
        }
        $oppositePoints = $type === 'odf'
            ? collect(data_get($preview, 'odo_placement.odos', []))->pluck('point')
            : collect(data_get($preview, 'odf_placement.odfs', []))->pluck('point')->concat(
                $project->odfs()->whereNotNull('latitude')->whereNotNull('longitude')->get()->map(fn ($odf) => [(float) $odf->latitude, (float) $odf->longitude])
            );
        if ($oppositePoints->contains(fn ($other) => is_array($other) && $this->geometry->distanceBetweenPoints($snap['point'], $other) < self::MIN_ODF_ODO_DISTANCE_M)) {
            throw new DomainException('ODF i ODO moraju biti međusobno udaljeni najmanje 5 m.');
        }
        $items[$index]['point'] = $snap['point'];
        $items[$index]['corridor_id'] = $snap['corridor_id'];
        data_set($preview, implode('.', $path), $items);

        return $this->recalculate($project, $preview);
    }

    public function assignHouse(Project $project, array $preview, int $houseId, string $odoKey): array
    {
        $odos = data_get($preview, 'odo_placement.odos', []);
        $targetIndex = collect($odos)->search(fn (array $odo) => $odo['key'] === $odoKey);
        if ($targetIndex === false) {
            throw new DomainException('Odabrani ODO nije pronađen.');
        }
        if ($odos[$targetIndex]['locked'] ?? false) {
            throw new DomainException('Zaključanom ODO-u nije moguće mijenjati kuće.');
        }
        $house = $project->houses()->findOrFail($houseId);
        if ($house->latitude === null || $house->longitude === null) {
            throw new DomainException('Kuća nema ispravne koordinate.');
        }

        foreach ($odos as &$odo) {
            if (($odo['locked'] ?? false) && in_array($houseId, $odo['house_ids'] ?? [], true)) {
                throw new DomainException('Kuća pripada zaključanom ODO-u.');
            }
            $odo['house_ids'] = array_values(array_filter($odo['house_ids'] ?? [], fn (int $id) => $id !== $houseId));
            $odo['occupancy'] = count($odo['house_ids']);
        }
        unset($odo);

        if ($odos[$targetIndex]['occupancy'] >= $odos[$targetIndex]['capacity']) {
            throw new DomainException('Odabrani ODO nema slobodnog kapaciteta.');
        }
        $distance = $this->geometry->distanceBetweenPoints($odos[$targetIndex]['point'], [(float) $house->latitude, (float) $house->longitude]);
        $maxDrop = (int) $project->largePlannerSetting()->firstOrFail()->max_drop_length_m;
        if ($distance > $maxDrop) {
            throw new DomainException("Kuća je udaljena više od dozvoljenih {$maxDrop} m od ODO-a.");
        }
        $odos[$targetIndex]['house_ids'][] = $houseId;
        sort($odos[$targetIndex]['house_ids']);
        $odos[$targetIndex]['occupancy'] = count($odos[$targetIndex]['house_ids']);
        data_set($preview, 'odo_placement.odos', $odos);

        return $this->recalculate($project, $preview);
    }

    public function lock(array $preview, string $type, string $key, bool $locked): array
    {
        $path = $type === 'odf' ? 'odf_placement.odfs' : 'odo_placement.odos';
        $items = data_get($preview, $path, []);
        $index = collect($items)->search(fn (array $item) => $item['key'] === $key);
        if ($index === false) {
            throw new DomainException('Predloženi element nije pronađen.');
        }
        $items[$index]['locked'] = $locked;
        data_set($preview, $path, $items);

        return $preview;
    }

    public function recalculate(Project $project, array $preview): array
    {
        $graph = $this->graphs->build($project);
        $placement = $this->refreshPlacement($project, $preview['odo_placement']);
        $odfPlan = $this->refreshPrimaryRoutes($project, $graph, $preview['odf_placement']);
        $routes = $this->routes->propose($project, $graph, $placement, $odfPlan);
        $capacity = $this->capacities->calculate($project, $placement, $odfPlan, $routes);
        $preview['graph'] = $graph;
        $preview['odo_placement'] = $placement;
        $preview['odf_placement'] = $odfPlan;
        $preview['routes'] = $routes;
        $preview['cable_capacity'] = $capacity;
        $preview['warnings'] = $this->warnings->collect($preview['clustering'], $placement, $odfPlan, $routes, $capacity);
        $preview['edited_at'] = now()->toIso8601String();

        return $preview;
    }

    private function refreshPlacement(Project $project, array $placement): array
    {
        $houses = $project->houses()->get()->keyBy('id');
        $maxDrop = (int) $project->largePlannerSetting()->firstOrFail()->max_drop_length_m;
        $warnings = [];
        foreach ($placement['odos'] as &$odo) {
            $distances = collect($odo['house_ids'] ?? [])->map(function (int $houseId) use ($houses, $odo): float {
                $house = $houses->get($houseId);

                return $house ? $this->geometry->distanceBetweenPoints($odo['point'], [(float) $house->latitude, (float) $house->longitude]) : 0;
            });
            $odo['total_drop_length_m'] = (int) round($distances->sum());
            $odo['max_drop_distance_m'] = (int) ceil($distances->max() ?? 0);
            $odo['within_drop_limit'] = $odo['max_drop_distance_m'] <= $maxDrop;
            if (! $odo['within_drop_limit']) {
                $warnings[] = ['code' => 'odo_drop_limit_exceeded', 'odo_key' => $odo['key'], 'message' => "Za {$odo['provisional_name']} maksimalni drop prelazi {$maxDrop} m.", 'max_distance_m' => $odo['max_drop_distance_m']];
            }
        }
        unset($odo);
        $placement['warnings'] = $warnings;

        return $placement;
    }

    private function refreshPrimaryRoutes(Project $project, array $graph, array $odfPlan): array
    {
        return $this->odfProposals->reconnect($project, $graph, $odfPlan);
    }

    private function nearestCorridor(Project $project, array $point): ?array
    {
        $best = null;
        foreach (GisSegment::query()->where('project_id', $project->id)->where('is_allowed', true)->whereNotNull('planning_corridor_type')->get() as $corridor) {
            $projection = $this->geometry->projectPointToPath($point, $corridor->path ?? []);
            if ($best === null || $projection['distance_m'] < $best['distance_m']) {
                $best = ['distance_m' => $projection['distance_m'], 'point' => [$projection['lat'], $projection['lng']], 'corridor_id' => $corridor->id];
            }
        }

        return $best;
    }
}

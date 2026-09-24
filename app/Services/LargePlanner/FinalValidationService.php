<?php

namespace App\Services\LargePlanner;

use App\Models\Project;
use App\Services\GeometryService;

class FinalValidationService
{
    public function __construct(private readonly GeometryService $geometry) {}

    public function validate(Project $project, array $preview): array
    {
        $errors = [];
        if ((int) ($preview['project_id'] ?? 0) !== $project->id) {
            $errors[] = $this->error('project_mismatch', 'Preview ne pripada odabranom projektu.');
        }

        $odos = collect(data_get($preview, 'odo_placement.odos', []));
        $odoKeys = $odos->pluck('key')->all();
        $proposedOdfs = collect(data_get($preview, 'odf_placement.odfs', []));
        $proposedOdfPoints = $proposedOdfs->mapWithKeys(fn (array $odf) => [$odf['key'] => $odf['point'] ?? null]);
        $proposedOdfKeys = $proposedOdfs->pluck('key')->all();
        $projectOdfIds = $project->odfs()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $primary = collect(data_get($preview, 'odf_placement.primary_routes', []));
        $secondary = collect(data_get($preview, 'routes.secondary_routes', []));
        $drops = collect(data_get($preview, 'routes.drop_routes', []));
        $assignments = [];
        foreach ($odos as $odo) {
            if ((int) ($odo['occupancy'] ?? 0) !== count($odo['house_ids'] ?? [])) {
                $errors[] = $this->error('odo_occupancy_mismatch', "Zauzeće za {$odo['key']} ne odgovara broju kuća.", ['odo_key' => $odo['key']]);
            }
            if (count($odo['house_ids'] ?? []) > (int) ($odo['capacity'] ?? 0)) {
                $errors[] = $this->error('odo_capacity_exceeded', "Kapacitet za {$odo['key']} je prekoračen.", ['odo_key' => $odo['key']]);
            }
            foreach ($odo['house_ids'] ?? [] as $houseId) {
                $assignments[$houseId][] = $odo['key'];
            }
            if ($secondary->where('odo_key', $odo['key'])->count() !== 1) {
                $errors[] = $this->error('invalid_odo_parent', "{$odo['key']} mora imati tačno jednu vezu prema ODF-u.", ['odo_key' => $odo['key']]);
            }
        }

        foreach ($secondary as $route) {
            if (! in_array($route['odo_key'] ?? null, $odoKeys, true)) {
                $errors[] = $this->error('unknown_odo_reference', "Sekundarna trasa {$route['key']} upućuje na nepostojeći ODO.", ['route_key' => $route['key']]);
            }
            $hasExisting = isset($route['odf_id']) && $route['odf_id'] !== null;
            $hasProposed = isset($route['odf_key']) && $route['odf_key'] !== null;
            if ($hasExisting === $hasProposed
                || ($hasExisting && ! in_array((int) $route['odf_id'], $projectOdfIds, true))
                || ($hasProposed && ! in_array($route['odf_key'], $proposedOdfKeys, true))) {
                $errors[] = $this->error('invalid_odf_reference', "Sekundarna trasa {$route['key']} nema valjan ODF ovog projekta.", ['route_key' => $route['key']]);
            }
            $odoPoint = $odos->firstWhere('key', $route['odo_key'] ?? null)['point'] ?? null;
            $odfPoint = $hasExisting
                ? optional($project->odfs()->find($route['odf_id']))->only(['latitude', 'longitude'])
                : $proposedOdfPoints->get($route['odf_key'] ?? '');
            if (is_array($odfPoint) && array_key_exists('latitude', $odfPoint)) {
                $odfPoint = [(float) $odfPoint['latitude'], (float) $odfPoint['longitude']];
            }
            if (! $this->connects($route['path'] ?? [], $odfPoint, $odoPoint)) {
                $errors[] = $this->error('route_endpoint_mismatch', "Sekundarna trasa {$route['key']} ne dodiruje svoj ODF i ODO.", ['route_key' => $route['key']]);
            }
        }

        $projectHouseIds = $project->houses()->pluck('id')->map(fn ($id) => (int) $id);
        foreach ($projectHouseIds as $houseId) {
            $count = count($assignments[$houseId] ?? []);
            if ($count !== 1) {
                $errors[] = $this->error('invalid_house_assignment', "Kuća #{$houseId} mora pripadati tačno jednom ODO-u.", ['house_id' => $houseId, 'assignments' => $count]);
            }
            if ($drops->where('house_id', $houseId)->count() !== 1) {
                $errors[] = $this->error('invalid_house_drop', "Kuća #{$houseId} mora imati tačno jednu drop trasu.", ['house_id' => $houseId]);
            }
        }
        foreach ($drops as $drop) {
            $assignedOdo = collect($assignments[$drop['house_id'] ?? 0] ?? [])->first();
            if (! in_array($drop['odo_key'] ?? null, $odoKeys, true) || $assignedOdo !== ($drop['odo_key'] ?? null)) {
                $errors[] = $this->error('invalid_drop_reference', "Drop trasa {$drop['key']} ne odgovara dodjeli kuće i ODO-a.", ['route_key' => $drop['key'], 'house_id' => $drop['house_id'] ?? null]);
            }
            $odoPoint = $odos->firstWhere('key', $drop['odo_key'] ?? null)['point'] ?? null;
            $house = $project->houses()->find($drop['house_id'] ?? 0);
            $housePoint = $house ? [(float) $house->latitude, (float) $house->longitude] : null;
            if (! $this->connects($drop['path'] ?? [], $odoPoint, $housePoint)) {
                $errors[] = $this->error('route_endpoint_mismatch', "Drop trasa {$drop['key']} ne dodiruje svoj ODO i kuću.", ['route_key' => $drop['key']]);
            }
        }
        $foreignHouseIds = array_diff(array_map('intval', array_keys($assignments)), $projectHouseIds->all());
        foreach ($foreignHouseIds as $houseId) {
            $errors[] = $this->error('foreign_house', "Kuća #{$houseId} ne pripada projektu.", ['house_id' => $houseId]);
        }

        foreach ($primary as $route) {
            $sourceKey = $route['from_odf_key'] ?? null;
            $sourceId = $route['from_odf_id'] ?? null;
            if (! in_array($route['to_odf_key'] ?? null, $proposedOdfKeys, true)
                || ($sourceKey === null && ! in_array((int) $sourceId, $projectOdfIds, true))
                || ($sourceKey !== null && ! in_array($sourceKey, $proposedOdfKeys, true))) {
                $errors[] = $this->error('invalid_primary_reference', "Primarna trasa {$route['key']} ima nevaljan ODF kraj.", ['route_key' => $route['key']]);
            }
            $sourcePoint = $sourceKey !== null
                ? $proposedOdfPoints->get($sourceKey)
                : (($source = $project->odfs()->find($sourceId)) ? [(float) $source->latitude, (float) $source->longitude] : null);
            if (! $this->connects($route['path'] ?? [], $sourcePoint, $proposedOdfPoints->get($route['to_odf_key'] ?? ''))) {
                $errors[] = $this->error('route_endpoint_mismatch', "Primarna trasa {$route['key']} ne dodiruje oba ODF-a.", ['route_key' => $route['key']]);
            }
        }
        if ($this->hasOdfCycle($primary->all())) {
            $errors[] = $this->error('odf_cycle', 'Primarne ODF veze sadrže ciklus.');
        }

        foreach ($primary->concat($secondary)->concat($drops) as $route) {
            if (count($route['path'] ?? []) < 2 || (float) ($route['length_m'] ?? 0) < 0) {
                $errors[] = $this->error('invalid_route_geometry', "Trasa {$route['key']} nema valjanu geometriju.", ['route_key' => $route['key']]);
            }
        }
        foreach (data_get($preview, 'warnings.items', []) as $warning) {
            if (($warning['severity'] ?? null) === 'error') {
                $errors[] = $this->error('preview_error', $warning['message'] ?? 'Preview sadrži kritičnu grešku.', ['source_code' => $warning['code'] ?? null]);
            }
        }

        $errors = collect($errors)->unique(fn (array $error) => implode('|', [$error['code'], $error['details']['house_id'] ?? '', $error['details']['odo_key'] ?? '', $error['details']['route_key'] ?? '', $error['details']['source_code'] ?? '']))->values();

        return [
            'valid' => $errors->isEmpty(),
            'errors' => $errors->all(),
            'summary' => [
                'houses' => $projectHouseIds->count(),
                'odos' => $odos->count(),
                'odfs' => $proposedOdfs->count(),
                'routes' => $primary->count() + $secondary->count() + $drops->count(),
                'errors' => $errors->count(),
            ],
        ];
    }

    private function error(string $code, string $message, array $details = []): array
    {
        return compact('code', 'message', 'details');
    }

    private function hasOdfCycle(array $routes): bool
    {
        $edges = [];
        foreach ($routes as $route) {
            if (($route['from_odf_key'] ?? null) !== null && ($route['to_odf_key'] ?? null) !== null) {
                $edges[$route['from_odf_key']][] = $route['to_odf_key'];
            }
        }
        $visiting = [];
        $visited = [];
        $walk = function (string $node) use (&$walk, &$visiting, &$visited, $edges): bool {
            if (isset($visiting[$node])) {
                return true;
            }
            if (isset($visited[$node])) {
                return false;
            }
            $visiting[$node] = true;
            foreach ($edges[$node] ?? [] as $next) {
                if ($walk($next)) {
                    return true;
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;

            return false;
        };

        return collect(array_keys($edges))->contains(fn (string $node) => $walk($node));
    }

    private function connects(array $path, ?array $first, ?array $second): bool
    {
        if (count($path) < 2 || $first === null || $second === null) {
            return false;
        }
        $ends = [$path[0], $path[count($path) - 1]];
        $matches = fn (array $point, array $target) => $this->geometry->distanceBetweenPoints($point, $target) <= 3;

        return ($matches($ends[0], $first) && $matches($ends[1], $second))
            || ($matches($ends[1], $first) && $matches($ends[0], $second));
    }
}

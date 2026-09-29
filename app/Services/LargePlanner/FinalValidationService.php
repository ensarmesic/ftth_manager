<?php

namespace App\Services\LargePlanner;

use App\Models\Project;
use App\Services\GeometryService;

class FinalValidationService
{
    private const MIN_ODF_ODO_DISTANCE_M = 5.0;

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
        $projectOdfs = $project->odfs()->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $manualProjectOdfs = $projectOdfs->whereNull('import_batch')->values();
        if ($manualProjectOdfs->isNotEmpty()) {
            $projectOdfs = $manualProjectOdfs;
        }
        $projectOdfIds = $projectOdfs->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allOdfPoints = $proposedOdfs->filter(fn (array $odf) => is_array($odf['point'] ?? null))
            ->map(fn (array $odf) => ['key' => $odf['key'], 'point' => $odf['point']])
            ->concat($projectOdfs->map(fn ($odf) => [
                'key' => 'odf-id-'.$odf->id,
                'point' => [(float) $odf->latitude, (float) $odf->longitude],
            ]));
        $primary = collect(data_get($preview, 'odf_placement.primary_routes', []));
        $secondary = collect(data_get($preview, 'routes.secondary_routes', []));
        $drops = collect(data_get($preview, 'routes.drop_routes', []));
        $assignments = [];
        foreach ($odos as $odo) {
            foreach (is_array($odo['point'] ?? null) ? $allOdfPoints : [] as $odf) {
                if ($this->geometry->distanceBetweenPoints($odo['point'], $odf['point']) < self::MIN_ODF_ODO_DISTANCE_M) {
                    $errors[] = $this->error('odf_odo_overlap', "{$odo['key']} ne smije biti postavljen na ODF.", ['odo_key' => $odo['key'], 'odf_key' => $odf['key']]);
                }
            }
            if ((int) ($odo['occupancy'] ?? 0) !== count($odo['house_ids'] ?? [])) {
                $errors[] = $this->error('odo_occupancy_mismatch', "Zauzeće za {$odo['key']} ne odgovara broju kuća.", ['odo_key' => $odo['key']]);
            }
            if (count($odo['house_ids'] ?? []) > (int) ($odo['capacity'] ?? 0)) {
                $errors[] = $this->error('odo_capacity_exceeded', "Kapacitet za {$odo['key']} je prekoračen.", ['odo_key' => $odo['key']]);
            }
            foreach ($odo['house_ids'] ?? [] as $houseId) {
                $assignments[$houseId][] = $odo['key'];
            }
            if ($secondary->filter(fn (array $route) => in_array($odo['key'], $route['odo_keys'] ?? [$route['odo_key'] ?? null], true))->count() !== 1) {
                $errors[] = $this->error('invalid_odo_parent', "{$odo['key']} mora imati tačno jednu vezu prema ODF-u.", ['odo_key' => $odo['key']]);
            }
        }

        foreach ($secondary as $route) {
            $routeOdoKeys = array_values($route['odo_keys'] ?? [$route['odo_key'] ?? null]);
            if (count($routeOdoKeys) > 8) {
                $errors[] = $this->error('secondary_branch_too_large', "Sekundarna trasa {$route['key']} ima više od 8 ODO ormarića.", ['route_key' => $route['key']]);
            }
            if (collect($routeOdoKeys)->contains(fn ($key) => ! in_array($key, $odoKeys, true))) {
                $errors[] = $this->error('unknown_odo_reference', "Sekundarna trasa {$route['key']} upućuje na nepostojeći ODO.", ['route_key' => $route['key']]);
            }
            $hasExisting = isset($route['odf_id']) && $route['odf_id'] !== null;
            $hasProposed = isset($route['odf_key']) && $route['odf_key'] !== null;
            if ($hasExisting === $hasProposed
                || ($hasExisting && ! in_array((int) $route['odf_id'], $projectOdfIds, true))
                || ($hasProposed && ! in_array($route['odf_key'], $proposedOdfKeys, true))) {
                $errors[] = $this->error('invalid_odf_reference', "Sekundarna trasa {$route['key']} nema valjan ODF ovog projekta.", ['route_key' => $route['key']]);
            }
            if ($hasProposed) {
                $plannedOdf = $proposedOdfs->firstWhere('key', $route['odf_key']);
                if (array_key_exists('odo_keys', $plannedOdf ?? []) && array_diff($routeOdoKeys, $plannedOdf['odo_keys']) !== []) {
                    $errors[] = $this->error('odo_assigned_to_wrong_odf', "Sekundarna trasa {$route['key']} sadrži ODO koji nije dodijeljen tom ODF-u.", ['route_key' => $route['key']]);
                }
            }
            $odoPoint = $odos->firstWhere('key', $route['terminal_odo_key'] ?? $route['odo_key'] ?? null)['point'] ?? null;
            $odfPoint = $hasExisting
                ? optional($project->odfs()->find($route['odf_id']))->only(['latitude', 'longitude'])
                : $proposedOdfPoints->get($route['odf_key'] ?? '');
            if (is_array($odfPoint) && array_key_exists('latitude', $odfPoint)) {
                $odfPoint = [(float) $odfPoint['latitude'], (float) $odfPoint['longitude']];
            }
            if (! $this->connects($route['path'] ?? [], $odfPoint, $odoPoint)) {
                $errors[] = $this->error('route_endpoint_mismatch', "Sekundarna trasa {$route['key']} ne dodiruje svoj ODF i ODO.", ['route_key' => $route['key']]);
            }
            $previousChainage = -INF;
            foreach ($routeOdoKeys as $odoKey) {
                $point = $odos->firstWhere('key', $odoKey)['point'] ?? null;
                $position = is_array($point) ? $this->positionOnPath($point, $route['path'] ?? []) : null;
                if ($position === null || $position['distance_m'] > 3) {
                    $errors[] = $this->error('secondary_route_misses_odo', "Sekundarna trasa {$route['key']} ne prolazi kroz {$odoKey}.", ['route_key' => $route['key'], 'odo_key' => $odoKey]);

                    continue;
                }
                if ($previousChainage > $position['chainage_m'] + 0.5) {
                    $errors[] = $this->error('secondary_odo_order_invalid', "Redoslijed ODO ormarića na trasi {$route['key']} nije pravilan.", ['route_key' => $route['key'], 'odo_key' => $odoKey]);
                }
                $previousChainage = max($previousChainage, $position['chainage_m']);
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

        $resolveOdf = function (array $route, string $side) use ($proposedOdfKeys, $proposedOdfPoints, $projectOdfIds, $projectOdfs): ?array {
            $key = $route[$side.'_odf_key'] ?? null;
            $id = $route[$side.'_odf_id'] ?? null;
            if (($key === null) === ($id === null)) {
                return null;
            }
            if ($key !== null) {
                return in_array($key, $proposedOdfKeys, true)
                    ? ['node' => 'key:'.$key, 'point' => $proposedOdfPoints->get($key)]
                    : null;
            }
            $numericId = (int) $id;
            $odf = $projectOdfs->firstWhere('id', $numericId);

            return in_array($numericId, $projectOdfIds, true) && $odf
                ? ['node' => 'id:'.$numericId, 'point' => [(float) $odf->latitude, (float) $odf->longitude]]
                : null;
        };
        $primaryEdges = [];
        foreach ($primary as $route) {
            $source = $resolveOdf($route, 'from');
            $target = $resolveOdf($route, 'to');
            if ($source === null || $target === null || $source['node'] === $target['node']) {
                $errors[] = $this->error('invalid_primary_reference', "Primarna trasa {$route['key']} ima nevaljan ODF kraj.", ['route_key' => $route['key']]);

                continue;
            }
            $primaryEdges[] = [$source['node'], $target['node']];
            if (! $this->connects($route['path'] ?? [], $source['point'], $target['point'])) {
                $errors[] = $this->error('route_endpoint_mismatch', "Primarna trasa {$route['key']} ne dodiruje oba ODF-a.", ['route_key' => $route['key']]);
            }
        }
        $allOdfNodes = collect($projectOdfIds)->map(fn (int $id) => 'id:'.$id)
            ->concat(collect($proposedOdfKeys)->map(fn (string $key) => 'key:'.$key))->all();
        if (! $this->allOdfsConnected($allOdfNodes, $primaryEdges)) {
            $errors[] = $this->error('odf_network_disconnected', 'Svi ODF-ovi moraju biti povezani primarnim krakovima u jednu mrežu.');
        }
        if ($this->hasOdfCycle($primaryEdges)) {
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

    private function allOdfsConnected(array $nodes, array $edges): bool
    {
        if (count($nodes) < 2) {
            return true;
        }
        $adjacency = array_fill_keys($nodes, []);
        foreach ($edges as [$from, $to]) {
            $adjacency[$from][] = $to;
            $adjacency[$to][] = $from;
        }
        $visited = [];
        $queue = [$nodes[0]];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (isset($visited[$node])) {
                continue;
            }
            $visited[$node] = true;
            foreach ($adjacency[$node] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return count($visited) === count($nodes);
    }

    private function hasOdfCycle(array $edges): bool
    {
        $adjacency = [];
        foreach ($edges as [$from, $to]) {
            $adjacency[$from][] = $to;
            $adjacency[$to][] = $from;
        }
        $visited = [];
        $walk = function (string $node, ?string $parent = null) use (&$walk, &$visited, $adjacency): bool {
            if (isset($visited[$node])) {
                return false;
            }
            $visited[$node] = true;
            foreach ($adjacency[$node] ?? [] as $next) {
                if ($next !== $parent && (isset($visited[$next]) || $walk($next, $node))) {
                    return true;
                }
            }

            return false;
        };

        return collect(array_keys($adjacency))->contains(fn (string $node) => ! isset($visited[$node]) && $walk($node));
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

    private function positionOnPath(array $point, array $path): ?array
    {
        if (count($path) < 2) {
            return null;
        }
        $best = null;
        $chainage = 0.0;
        for ($index = 1; $index < count($path); $index++) {
            $segment = [$path[$index - 1], $path[$index]];
            $projection = $this->geometry->projectPointToPath($point, $segment);
            $projected = [$projection['lat'], $projection['lng']];
            $candidate = [
                'distance_m' => $projection['distance_m'],
                'chainage_m' => $chainage + $this->geometry->distanceBetweenPoints($segment[0], $projected),
            ];
            if ($best === null || $candidate['distance_m'] < $best['distance_m']) {
                $best = $candidate;
            }
            $chainage += $this->geometry->distanceBetweenPoints($segment[0], $segment[1]);
        }

        return $best;
    }
}

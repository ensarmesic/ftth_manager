<?php

namespace App\Services;

use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SurveyNetworkPersistenceService
{
    public function __construct(
        private readonly GeometryService $geometry,
        private readonly BranchSyncService $branchSync,
        private readonly SurveyImportIdentityService $identity,
        private readonly SurveyDuctBindingService $ductBinding,
        private readonly SurveyAppendixImportService $appendixImporter,
        private readonly SurveyRouteReconciliationService $routeReconciliation,
    ) {}

    /** @return array{trenches:int,ducts:int,manholes:int,borings:int,splices:int,loops:int} */
    public function persist(
        Project $project,
        array $points,
        array $network,
        Collection $cabinets,
        Collection $odfs,
        Collection $houses,
        array $cabinetOverrides,
        string $batch,
        float $elementToleranceM,
        float $ductEndpointBindM,
    ): array {
        $counts = ['trenches' => 0, 'ducts' => 0, 'manholes' => 0, 'borings' => 0, 'splices' => 0, 'loops' => 0];
        $freshRouteIds = [];
        $freshDropHouseIds = [];
        $trenchGroup = 'Geodetski rov '.substr($batch, 0, 8);

        foreach ($network['trenches'] as $index => $chain) {
            if ($chain['_routing_only'] ?? false) {
                continue;
            }
            // A trench network is a tree, not one walkable polyline. Persist every
            // chain between junctions as its own route and group the chains logically.
            // Merging merely touching chains forces a branch to be encoded as an
            // out-and-back path, which draws the same black segment twice.
            $trench = NetworkRoute::create([
                'project_id' => $project->id,
                'name' => $this->identity->uniqueName(NetworkRoute::class, $project->id, 'Dionica rova '.($index + 1)),
                'route_type' => 'trench', 'installation_type' => 'underground', 'counts_as_trench' => true,
                'trench_group' => $trenchGroup,
                'duct_length_m' => $chain['length_m'], 'fiber_length_m' => 0, 'fiber_count' => 0,
                'microduct_count' => 0, 'microduct_type' => null, 'status' => 'planned',
                'path' => $chain['path'], 'note' => 'Geodetski snimak: '.$chain['code'], 'import_batch' => $batch,
            ]);
            $freshRouteIds[] = $trench->id;
            $counts['trenches']++;
        }

        foreach ($network['ducts'] as $duct) {
            $binding = $this->ductBinding->resolve($duct, $cabinets, $odfs, $houses, $cabinetOverrides, $elementToleranceM, $ductEndpointBindM);
            $cabinet = $binding['cabinet'];
            $odf = $binding['odf'];
            $house = $binding['house'];
            $routeType = $binding['route_type'];
            // Persist exactly the same proven end-to-end geometry shown in preview:
            // house/sling -> private branch -> shared/main trench -> assigned ODO.
            // The old code truncated every drop at its first contact with the main
            // route, so the red shared section disappeared immediately after import.
            $persistedPath = $this->geometry->compactPath($duct['path']);
            $this->ductBinding->assignCabinetToOdf($cabinet, $odf);

            if ($routeType === 'drop' && $house !== null && isset($freshDropHouseIds[$house->id])) {
                continue;
            }

            if ((($duct['prepared_sling'] ?? false) || isset($duct['_terminal_point'])) && $duct['zo_tag'] !== null) {
                $cabinetReached = (bool) ($duct['cabinet_reached'] ?? false)
                    || ($cabinet !== null && min(...array_map(
                        fn (array $endpoint) => $this->geometry->distanceMeters(
                            (float) $cabinet->latitude, (float) $cabinet->longitude, $endpoint[0], $endpoint[1]
                        ),
                        $this->pathEndpoints($duct['path'])
                    )) <= $ductEndpointBindM);
                if (! $cabinetReached) {
                    throw new InvalidArgumentException('Korisnicka linija '.($duct['house_ref'] ?? $duct['label']).' nema dokazani povezani put do ZO '.$duct['zo_tag'].'. Uvoz je zaustavljen.');
                }
            }

            $existing = $this->routeReconciliation->findExistingDuctRoute($project->id, $duct, $routeType, $house?->id, $freshRouteIds, $elementToleranceM);
            if ($existing) {
                $mergedPath = $this->routeReconciliation->mergeTouchingPaths($existing->path ?? [], $persistedPath, $elementToleranceM);
                $existing->update([
                    'path' => $mergedPath,
                    'duct_length_m' => $this->geometry->polylineLength($mergedPath),
                    'microduct_count' => max((int) $existing->microduct_count, (int) $duct['microduct_count']),
                    'note' => $this->routeReconciliation->appendImportNote($existing->note, $this->routeReconciliation->ductImportNote($duct)),
                ]);
                if ($routeType === 'drop' && $house !== null) {
                    $this->ductBinding->assignDropCabinetToHouse($house, $cabinet);
                    $freshDropHouseIds[$house->id] = true;
                }

                continue;
            }

            [$fromType, $fromId, $toType, $toId] = $routeType === 'drop'
                ? ['cabinet', $cabinet?->id, 'house', $house?->id]
                : [$odf ? 'odf' : null, $odf?->id, $cabinet ? 'cabinet' : null, $cabinet?->id];

            $route = NetworkRoute::create([
                'project_id' => $project->id, 'odf_id' => $odf?->id, 'cabinet_id' => $cabinet?->id,
                'from_type' => $fromType, 'from_id' => $fromId, 'to_type' => $toType, 'to_id' => $toId,
                'name' => $this->identity->uniqueName(NetworkRoute::class, $project->id, $duct['label']),
                'route_type' => $routeType, 'installation_type' => 'underground', 'counts_as_trench' => false,
                'duct_length_m' => $this->geometry->polylineLength($persistedPath), 'fiber_length_m' => 0, 'fiber_count' => 0,
                'microduct_count' => $duct['microduct_count'], 'microduct_type' => $duct['microduct_type'],
                'status' => 'planned', 'path' => $persistedPath,
                'coordinates_json' => $routeType === 'drop' ? [
                    'own_geometry' => $persistedPath,
                    'target_zo' => $duct['target_zo'] ?? ($duct['zo_tag'] !== null ? 'ZO-'.$duct['zo_tag'] : null),
                    'shared_route_edges' => $duct['shared_route_edges'] ?? [],
                ] : null,
                'note' => $this->routeReconciliation->ductImportNote($duct), 'import_batch' => $batch,
            ]);
            $freshRouteIds[] = $route->id;
            if ($routeType === 'drop' && $house !== null) {
                $this->ductBinding->assignDropCabinetToHouse($house, $cabinet);
                $freshDropHouseIds[$house->id] = true;
            }
            $this->branchSync->createBranchForRoute($route);
            $counts['ducts']++;
        }

        $counts['manholes'] = $this->appendixImporter->importKind($project->id, $points, 'manhole', 'manhole', $batch, $elementToleranceM);
        $counts['borings'] = $this->appendixImporter->importKind($project->id, $points, 'boring', 'boring_fi_130', $batch, $elementToleranceM);
        $counts['splices'] = $this->appendixImporter->importKind($project->id, $points, 'splice', 'splice', $batch, $elementToleranceM);
        $counts['loops'] = $this->appendixImporter->importKind($project->id, $points, 'loop', 'loop', $batch, $elementToleranceM);

        return $counts;
    }

    private function pathEndpoints(array $path): array
    {
        return [$path[0], $path[count($path) - 1]];
    }
}

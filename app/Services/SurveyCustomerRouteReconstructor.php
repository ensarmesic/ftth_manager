<?php

namespace App\Services;

class SurveyCustomerRouteReconstructor
{
    public function __construct(
        private readonly SurveyTargetZoParser $targetParser,
        private readonly SurveyMainRouteGraphService $graphService,
        private readonly SurveyRouteEntryPointService $entryService,
        private readonly GeometryService $geometry,
        private readonly SurveyImportIdentityService $identity,
    ) {}

    /**
     * @param  array<int,array{0:float|int,1:float|int}>  $userPath
     * @param  array<int,array{id:int|string,path:array}>  $mainRoutes
     * @param  array<int,array{id:int|string,name:string,coordinate:array}>  $cabinets
     */
    public function reconstruct(string $description, array $userPath, array $mainRoutes, array $cabinets, ?array $preparedGraph = null): array
    {
        $target = $this->targetParser->parse($description);
        if (! $target['explicit']) {
            return $this->failure('target_missing', 'Ciljni ZO nije naveden u opisu.', $target);
        }

        $cabinet = collect($cabinets)->first(function (array $cabinet) use ($target): bool {
            $parsed = $this->targetParser->parse((string) $cabinet['name'])['target_zo'];
            $legacyTag = $this->identity->cabinetTag((string) $cabinet['name']);

            return $parsed === $target['target_zo']
                || ($legacyTag !== null && 'ZO-'.$legacyTag === $target['target_zo']);
        });
        if ($cabinet === null) {
            return $this->failure('target_not_found', 'Target '.$target['target_zo'].' nije pronađen.', $target);
        }

        $graph = $preparedGraph ?? $this->graphService->build($mainRoutes);
        if ($this->graphService->locateNode($graph, $cabinet['coordinate']) === null) {
            return $this->failure('network_path_not_found', 'Nije pronađen mrežni put do '.$target['target_zo'].'.', $target);
        }

        $start = $userPath[0] ?? null;
        $fullRoute = $start !== null
            ? $this->graphService->shortestPath($graph, $start, $cabinet['coordinate'])
            : null;
        if ($fullRoute === null && count($userPath) >= 2) {
            $graph = $this->graphService->build(array_merge($mainRoutes, [[
                'id' => 'customer:'.sha1(json_encode($userPath)),
                'path' => $userPath,
            ]]));
            $fullRoute = $this->graphService->shortestPath($graph, $start, $cabinet['coordinate']);
        }
        if ($fullRoute === null) {
            return $this->failure('network_path_not_found', 'Nije pronađen mrežni put do '.$target['target_zo'].'.', $target);
        }

        [$ownGeometry, $sharedGeometry, $sharedEdges, $entry] = $this->splitOwnAndShared($fullRoute, $userPath);

        return [
            'status' => 'complete',
            'error_code' => null,
            'message' => null,
            'raw_description' => $target['raw_description'],
            'target_zo' => $target['target_zo'],
            'target_zo_found' => true,
            'target_cabinet_id' => $cabinet['id'],
            'target_zo_coordinate' => $cabinet['coordinate'],
            'entry' => $entry,
            'own_geometry' => $ownGeometry,
            'shared_main_geometry' => $sharedGeometry,
            'shared_route_edges' => $sharedEdges,
            'full_geometry' => $fullRoute['path'],
            'warnings' => [],
        ];
    }

    private function splitOwnAndShared(array $route, array $userPath): array
    {
        $path = $route['path'];
        $transition = 0;
        foreach ($path as $index => $point) {
            if ($this->geometry->distanceToRoute($point[0], $point[1], $userPath) > SurveyMainRouteGraphService::NODE_MATCH_TOLERANCE_M) {
                break;
            }
            $transition = $index;
        }
        $entryPoint = $path[$transition];
        $source = $route['edge_sources'][$transition][0] ?? [];

        return [
            array_slice($path, 0, $transition + 1),
            array_slice($path, $transition),
            array_slice($route['edge_sources'], $transition),
            [
                'entry_point' => $entryPoint,
                'user_segment_end_index' => max(1, $transition),
                'main_route_id' => $source['route_id'] ?? null,
                'main_segment_index' => $source['segment_index'] ?? null,
                'match_type' => 'network_transition',
            ],
        ];
    }

    private function failure(string $code, string $message, array $target, ?array $entry = null): array
    {
        return [
            'status' => 'unresolved',
            'error_code' => $code,
            'message' => $message,
            'raw_description' => $target['raw_description'],
            'target_zo' => $target['target_zo'],
            'target_zo_found' => $code !== 'target_not_found',
            'target_zo_coordinate' => null,
            'entry' => $entry,
            'own_geometry' => [],
            'shared_main_geometry' => [],
            'shared_route_edges' => [],
            'full_geometry' => [],
            'warnings' => [$message],
        ];
    }
}

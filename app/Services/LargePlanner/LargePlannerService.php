<?php

namespace App\Services\LargePlanner;

use App\Models\Project;
use App\Services\LargePlannerReadinessService;
use DomainException;

class LargePlannerService
{
    public function __construct(
        private readonly LargePlannerReadinessService $readiness,
        private readonly CorridorGraphBuilder $graphs,
        private readonly HouseClusterer $clusterer,
        private readonly OdoPlacementService $odoPlacement,
        private readonly NetworkRouteProposalService $routeProposals,
        private readonly OdfProposalService $odfProposals,
        private readonly CableCapacityService $cableCapacities,
    ) {}

    public function prepare(Project $project): array
    {
        if ($project->planning_mode !== 'large_auto') {
            throw new DomainException('Veliki planer je dostupan samo za large_auto projekte.');
        }

        $readiness = $this->readiness->assess($project);
        if (! $readiness['ready']) {
            throw new DomainException('Ulazni podaci nisu spremni za proračun.');
        }

        $graph = $this->graphs->build($project);
        if ($graph['summary']['components'] !== 1) {
            throw new DomainException('Dozvoljeni koridori nisu povezani u jednu cjelinu.');
        }

        $clustering = $this->clusterer->cluster($project, $graph);

        $placement = $this->odoPlacement->propose($project, $clustering);
        $odfPlan = $this->odfProposals->propose($project, $graph, $placement);
        $routes = $this->routeProposals->propose($project, $graph, $placement, $odfPlan);

        return [
            'project_id' => $project->id,
            'readiness' => $readiness,
            'graph' => $graph,
            'clustering' => $clustering,
            'odo_placement' => $placement,
            'odf_placement' => $odfPlan,
            'routes' => $routes,
            'cable_capacity' => $this->cableCapacities->calculate($project, $placement, $odfPlan, $routes),
        ];
    }
}

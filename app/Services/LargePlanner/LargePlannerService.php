<?php

namespace App\Services\LargePlanner;

use App\Models\Project;
use App\Services\LargePlannerReadinessService;
use Closure;
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
        private readonly PlanWarningService $warnings,
    ) {}

    public function prepare(Project $project, ?Closure $progress = null): array
    {
        if ($project->planning_mode !== 'large_auto') {
            throw new DomainException('Veliki planer je dostupan samo za large_auto projekte.');
        }

        $this->report($progress, 5, 'Provjera spremnosti ulaznih podataka.');
        $readiness = $this->readiness->assess($project);
        if (! $readiness['ready']) {
            throw new DomainException('Ulazni podaci nisu spremni za proračun.');
        }

        $this->report($progress, 15, 'Izgradnja grafa dozvoljenih koridora.');
        $graph = $this->graphs->build($project);
        if ($graph['summary']['components'] !== 1) {
            throw new DomainException('Dozvoljeni koridori nisu povezani u jednu cjelinu.');
        }

        $this->report($progress, 30, 'Grupisanje kuća po zonama i kapacitetu.');
        $clustering = $this->clusterer->cluster($project, $graph);

        $this->report($progress, 50, 'Određivanje položaja ODO ormara.');
        $placement = $this->odoPlacement->propose($project, $clustering);
        $this->report($progress, 65, 'Proračun ODF-ova i primarnih krakova.');
        $odfPlan = $this->odfProposals->propose($project, $graph, $placement);
        $this->report($progress, 75, 'Proračun sekundarnih i drop trasa.');
        $routes = $this->routeProposals->propose($project, $graph, $placement, $odfPlan);
        $this->report($progress, 88, 'Dimenzionisanje kablova i provjera kapaciteta.');
        $capacity = $this->cableCapacities->calculate($project, $placement, $odfPlan, $routes);
        $this->report($progress, 95, 'Objedinjavanje upozorenja i završna provjera.');

        return [
            'project_id' => $project->id,
            'readiness' => $readiness,
            'graph' => $graph,
            'clustering' => $clustering,
            'odo_placement' => $placement,
            'odf_placement' => $odfPlan,
            'routes' => $routes,
            'cable_capacity' => $capacity,
            'warnings' => $this->warnings->collect($clustering, $placement, $odfPlan, $routes, $capacity),
        ];
    }

    private function report(?Closure $progress, int $percent, string $message): void
    {
        $progress?->__invoke($percent, $message);
    }
}

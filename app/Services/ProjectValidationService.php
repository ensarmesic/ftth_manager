<?php

namespace App\Services;

use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Support\Collection;

class ProjectValidationService
{
    public function __construct(
        private readonly GeometryService $geometry,
        private readonly ProjectMaterialService $materials,
    ) {}

    public function validateProject(Project $project): array
    {
        $items = [];
        $distanceLimit = $project->houseDistanceLimit();
        $configuredLargeCapacity = $project->largePlannerSetting?->odo_capacity;
        $largePlannerCapacity = $project->isAerial() ? 4 : ($project->planning_mode === 'large_auto' && $configuredLargeCapacity !== null ? (int) $configuredLargeCapacity : null);
        $project->loadMissing(['odfs.cabinets', 'houses.cabinet', 'cabinets.odf', 'cabinets.houses', 'routes']);
        $branchRoutes = $this->branchRoutes($project);

        if ($project->odfs->isEmpty()) {
            $items[] = $this->validationItem('warning', 'Projekat nema ODF.', 'project', $project->id, 'Dodaj ODF prije potvrde mrežnog plana.');
        }
        if ($project->cabinets->isEmpty()) {
            $items[] = $this->validationItem('info', $project->isAerial() ? 'Projekat nema ODP/NAP tačke na stubovima.' : 'Projekat nema ODO ormariće.', 'project', $project->id, $project->isAerial() ? 'Postavi priključne stubove i ODP/NAP tačke.' : 'Pokreni automatsko planiranje ODO ormarića.');
        }
        if ($project->houses->isEmpty()) {
            $items[] = $this->validationItem('info', 'Projekat nema kuće.', 'project', $project->id, 'Dodaj kuće iz mape ili liste.');
        }
        foreach ($project->odfs as $odf) {
            if ($odf->latitude === null || $odf->longitude === null) {
                $items[] = $this->validationItem('error', "{$odf->name} nema koordinate.", 'odf', $odf->id, 'Postavi ODF na mapi.');
            }
            if ($odf->cabinets->isEmpty()) {
                $items[] = $this->validationItem('warning', "{$odf->name} nema povezan ODO.", 'odf', $odf->id, 'Poveži najmanje jedan ODO na ODF.');
            }

            if ($odf->fiber_capacity > 0) {
                $usedFibers = $odf->cabinets->filter(fn ($c) => $c->parent_cabinet_id === null)->sum('splitter_count');
                if ($usedFibers > $odf->fiber_capacity) {
                    $items[] = $this->validationItem('error', "{$odf->name}: prekoračen kapacitet ({$usedFibers}/{$odf->fiber_capacity} vlakana).", 'odf', $odf->id, 'Smanji broj ODO ormarića ili povećaj kapacitet ODF-a.');
                } elseif ($usedFibers > 0 && $usedFibers > $odf->fiber_capacity * 0.85) {
                    $items[] = $this->validationItem('warning', "{$odf->name}: kapacitet skoro popunjen ({$usedFibers}/{$odf->fiber_capacity} vlakana).", 'odf', $odf->id, 'Razmotri dodavanje novog ODF-a.');
                }
            }
        }

        foreach ($project->houses as $house) {
            if ($house->latitude === null || $house->longitude === null) {
                $items[] = $this->validationItem('error', "{$house->label} nema koordinate.", 'house', $house->id, 'Postavi kuću na mapi.');
            }
            if (! $house->cabinet_id) {
                $items[] = $this->validationItem('warning', "{$house->label} nema povezan ODO.", 'house', $house->id, 'Dodijeli kuću ODO ormariću.');
            }
            if ($house->cabinet && $house->latitude && $house->longitude && $house->cabinet->latitude && $house->cabinet->longitude) {
                $distance = $this->distanceMeters((float) $house->latitude, (float) $house->longitude, (float) $house->cabinet->latitude, (float) $house->cabinet->longitude);
                if ($distance > $distanceLimit) {
                    $distanceLabel = number_format($distance, 1, ',', '.');
                    $items[] = $this->validationItem('warning', "{$house->label} je predaleko od ODO.", 'house', $house->id, "Udaljenost do dodijeljenog ODO {$house->cabinet->name}: {$distanceLabel} m (prag {$distanceLimit} m). Pomjeri taj ODO bliže ili promijeni dodjelu kuće.");
                }
            }
            if ($branchRoutes->isNotEmpty() && $house->latitude && $house->longitude) {
                $nearestDistance = $this->nearestBranchDistance((float) $house->latitude, (float) $house->longitude, $branchRoutes, $distanceLimit);
                if ($nearestDistance > $distanceLimit) {
                    $branchDistanceLabel = number_format($nearestDistance, 1, ',', '.');
                    $items[] = $this->validationItem('warning', "{$house->label} je predaleko od kraka.", 'house', $house->id, "Udaljenost do najbližeg kraka: {$branchDistanceLabel} m (prag {$distanceLimit} m). Dodaj krak bliže objektu ili provjeri položaj kuće.");
                }
                $hasExplicitBranchMismatch = $house->cabinet
                    && $house->branch_id
                    && $house->cabinet->branch_id
                    && (int) $house->branch_id !== (int) $house->cabinet->branch_id;
                if ($hasExplicitBranchMismatch) {
                    $items[] = $this->validationItem('error', "{$house->label} je povezana na ODO drugog kraka.", 'house', $house->id, 'Ponovi Auto ODO ili ručno ispravi vezu.');
                }
            }
        }

        foreach ($project->cabinets as $cabinet) {
            $houseCount = $cabinet->houses->count();
            $neededSplitters = $this->splitterCount($houseCount);
            if (! $cabinet->odf_id && ! $cabinet->parent_cabinet_id) {
                $items[] = $this->validationItem('warning', "{$cabinet->name} nema povezan ODF.", 'cabinet', $cabinet->id, 'Poveži ODO sa najbližim ODF-om.');
            }
            if ($cabinet->latitude === null || $cabinet->longitude === null) {
                $items[] = $this->validationItem('error', "{$cabinet->name} nema koordinate.", 'cabinet', $cabinet->id, 'Postavi ODO na mapi.');
            }
            if ($largePlannerCapacity === null && ($cabinet->splitter_count > 3 || $cabinet->ports_per_splitter > 4)) {
                $items[] = $this->validationItem('error', "{$cabinet->name} ima neispravnu splitter konfiguraciju.", 'cabinet', $cabinet->id, 'Koristi najviše 3 splittera sa po 4 porta.');
            }
            if ($houseCount === 0) {
                $items[] = $this->validationItem('info', "{$cabinet->name} nema povezanih kuća.", 'cabinet', $cabinet->id, 'Poveži kuće na ODO.');
            }
            if ($largePlannerCapacity === null && $houseCount > 12) {
                $items[] = $this->validationItem('error', "{$cabinet->name} ima više od 12 kuća.", 'cabinet', $cabinet->id, 'Rastereti ODO ili kreiraj dodatni ODO.');
            }
            if ($largePlannerCapacity !== null && ($houseCount > $largePlannerCapacity || $houseCount > $cabinet->capacity)) {
                $items[] = $this->validationItem('error', "{$cabinet->name} ima više kuća od dozvoljenog kapaciteta.", 'cabinet', $cabinet->id, "Smanji broj kuća ili povećaj kapacitet iznad {$houseCount} portova.");
            }
            if ($largePlannerCapacity === null && $cabinet->splitter_count < $neededSplitters) {
                $items[] = $this->validationItem('error', "{$cabinet->name} nema dovoljno splittera.", 'cabinet', $cabinet->id, "Postavi {$neededSplitters} splittera.");
            }
            if ($branchRoutes->isNotEmpty() && $cabinet->latitude && $cabinet->longitude) {
                if ($this->nearestBranchDistance((float) $cabinet->latitude, (float) $cabinet->longitude, $branchRoutes, 10) > 10) {
                    $items[] = $this->validationItem('warning', "{$cabinet->name} nije na trasi/kraku.", 'cabinet', $cabinet->id, 'Pomjeri ODO na najblizu tačku trase.');
                }
            }
        }

        $housesPerCabinet = $project->cabinets->mapWithKeys(fn ($cab) => [$cab->id => $cab->houses->count()])->all();
        $housesById = $project->houses->keyBy('id');
        $cabinetsById = $project->cabinets->keyBy('id');

        foreach ($project->routes as $route) {
            if ($route->route_type === 'trench') {
                if ($route->duct_length_m <= 0) {
                    $items[] = $this->validationItem('error', "{$route->name} nema ispravnu dužinu.", 'route', $route->id, 'Uredi geometriju trase.');
                }

                continue;
            }
            if (! $project->isAerial() && ! $route->hasCompleteMicroductData()) {
                $items[] = $this->validationItem('warning', "{$route->name} nema kompletne podatke o mikrocijevi.", 'route', $route->id, 'Unesi tip, broj i dužinu mikrocijevi.');
            }
            if ($project->isAerial() && ($route->installation_type !== 'aerial' || $route->cable_type !== 'ADSS')) {
                $items[] = $this->validationItem('error', "{$route->name} nije označena kao nadzemni ADSS.", 'route', $route->id, 'Postavi nadzemno polaganje i ADSS tip kabla.');
            }
            if ($route->hasIncompleteCableData()) {
                $items[] = $this->validationItem('warning', "{$route->name} ima broj vlakana, ali nema dužinu kabla.", 'route', $route->id, 'Unesi dužinu kabla ili ukloni broj vlakana.');
            }
            if (! $route->installation_type) {
                $items[] = $this->validationItem('warning', "{$route->name} nema tip polaganja.", 'route', $route->id, 'Odaberi podzemno ili zračno polaganje.');
            }
            if ($route->duct_length_m <= 0) {
                $items[] = $this->validationItem('error', "{$route->name} nema ispravnu dužinu.", 'route', $route->id, 'Uredi geometriju trase.');
            }
            if ($route->route_type === 'drop' && ($route->to_type !== 'house' || ! $route->to_id)) {
                $items[] = $this->validationItem('error', "{$route->name} drop trasa nema ciljnu kuću.", 'route', $route->id, 'Postavi to_type house i to_id kuće.');
            }
            if ($route->route_type === 'backbone' && (! $route->from_type || ! $route->from_id || ! $route->to_type || ! $route->to_id)) {
                $items[] = $this->validationItem('warning', "{$route->name} nema kompletne from/to veze.", 'route', $route->id, 'Poveži oba kraja trase.');
            }
            $occupancy = $this->routeOccupancy($route, $housesPerCabinet);
            if ($occupancy['used_fibers'] > $occupancy['fiber_capacity']) {
                $items[] = $this->validationItem('error', "{$route->name} ima više zauzetih vlakana od kapaciteta.", 'route', $route->id, 'Povećaj kapacitet kabla.');
            } elseif ($occupancy['utilization_percent'] > 80) {
                $items[] = $this->validationItem('warning', "{$route->name} ima preko 80% zauzeća vlakana.", 'route', $route->id, 'Planiraj rezervni kapacitet.');
            }
            if (! $route->path) {
                $items[] = $this->validationItem('warning', "{$route->name} nema geometriju.", 'route', $route->id, 'Uredi geometriju trase na mapi.');
            } elseif (count($route->path) < 2) {
                $items[] = $this->validationItem('error', "{$route->name} ima manje od dvije tačke.", 'route', $route->id, 'Dodaj najmanje dvije tačke trase.');
            } else {
                $path = array_values($route->path);
                $actualLength = $this->geometry->polylineLength($path);
                $lengthTolerance = max(5, $actualLength * 0.05);
                if (abs((float) $route->duct_length_m - $actualLength) > $lengthTolerance) {
                    $items[] = $this->validationItem('warning', "{$route->name}: spremljena dužina ne odgovara geometriji ({$route->duct_length_m} m / {$actualLength} m).", 'route', $route->id, 'Ponovo sačuvaj geometriju trase da se dužina preračuna.');
                }

                for ($index = 1; $index < count($path); $index++) {
                    if ($this->geometry->distanceBetweenPoints($path[$index - 1], $path[$index]) < 0.02) {
                        $items[] = $this->validationItem('warning', "{$route->name} ima uzastopne duple tačke.", 'route', $route->id, 'Očisti duple tačke geometrije.');

                        break;
                    }
                }

                if ($route->route_type === 'drop') {
                    $house = $route->to_type === 'house' ? $housesById->get($route->to_id) : null;
                    $cabinet = $route->cabinet_id ? $cabinetsById->get($route->cabinet_id) : null;
                    $endpoints = [$path[0], $path[count($path) - 1]];
                    if ($house && $house->latitude !== null && $house->longitude !== null && ! $this->endpointTouches($endpoints, (float) $house->latitude, (float) $house->longitude)) {
                        $items[] = $this->validationItem('error', "{$route->name} ne završava na povezanoj kući {$house->label}.", 'route', $route->id, 'Ponovo rutiraj drop od tačne koordinate kuće.');
                    }
                    if ($cabinet && $cabinet->latitude !== null && $cabinet->longitude !== null && ! $this->endpointTouches($endpoints, (float) $cabinet->latitude, (float) $cabinet->longitude)) {
                        $items[] = $this->validationItem('error', "{$route->name} ne završava na dodijeljenom ODO {$cabinet->name}.", 'route', $route->id, 'Ponovo rutiraj drop do tačne koordinate ODO-a.');
                    }
                }
            }
        }

        return $items ?: [$this->validationItem('ok', 'Projekat nema otvorenih FTTH upozorenja.', 'project', $project->id, 'Nastavi sa projektovanjem.')];
    }

    private function endpointTouches(array $endpoints, float $lat, float $lng, float $toleranceMeters = 1.5): bool
    {
        return collect($endpoints)->contains(fn (array $point) => $this->geometry->distanceMeters(
            (float) $point[0],
            (float) $point[1],
            $lat,
            $lng,
        ) <= $toleranceMeters);
    }

    private function branchRoutes(Project $project): Collection
    {
        return NetworkBranch::query()
            ->with('route')
            ->where('project_id', $project->id)
            ->where('type', 'secondary')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (NetworkBranch $branch): ?NetworkRoute {
                $route = $branch->route;
                if (! $route || count($route->path ?? []) < 2 || $route->route_type === 'trench') {
                    return null;
                }

                $route->setAttribute('planning_branch_id', $branch->id);
                $route->setAttribute('planning_branch_name', $branch->name);
                $route->setAttribute('planning_branch_code', $branch->code);
                $route->setAttribute('planning_branch_sort_order', $branch->sort_order);

                return $route;
            })
            ->filter()
            ->values();
    }

    /**
     * Distance to the nearest branch route. Stops early once a route within
     * $acceptableM is found, because callers only need the exact minimum
     * when it exceeds that limit (to report it).
     */
    private function nearestBranchDistance(float $lat, float $lng, Collection $routes, float $acceptableM): float
    {
        $best = INF;
        foreach ($routes as $route) {
            $distance = $this->geometry->projectPointToRoute($lat, $lng, $route)['distance_m'];
            if ($distance <= $acceptableM) {
                return $distance;
            }
            $best = min($best, $distance);
        }

        return $best;
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->geometry->distanceMeters($lat1, $lng1, $lat2, $lng2);
    }

    private function splitterCount(int $houseCount): int
    {
        return $this->materials->splitterCount($houseCount);
    }

    private function routeOccupancy(NetworkRoute $route, array $housesPerCabinet = []): array
    {
        return $this->materials->routeOccupancy($route, $housesPerCabinet);
    }

    private function validationItem(string $level, string $message, string $type, int $id, string $recommendation): array
    {
        return [
            'level' => $level,
            'message' => $message,
            'element_type' => $type,
            'element_id' => $id,
            'recommendation' => $recommendation,
        ];
    }
}
